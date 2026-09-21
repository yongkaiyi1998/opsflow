<?php

namespace App\Services;

use App\DocumentIntakeStatus;
use App\IntakeDocumentType;
use App\Models\Attachment;
use App\Models\DocumentIntake;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class PurchaseQuotationVerificationService
{
    public function __construct(
        private readonly PurchaseRequestService $purchaseRequests,
        private readonly AuditService $auditService,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function verify(DocumentIntake $documentIntake, array $attributes, User $actor): PurchaseRequest
    {
        Gate::forUser($actor)->authorize('verify', $documentIntake);

        if ($documentIntake->purchase_request_id === null) {
            $this->validatePrivateSource($documentIntake);
        }

        $sourceDisk = $documentIntake->disk;
        $sourcePath = $documentIntake->path;

        return DB::transaction(function () use ($documentIntake, $attributes, $actor, $sourceDisk, $sourcePath): PurchaseRequest {
            $lockedIntake = DocumentIntake::query()
                ->with('intakeBatch')
                ->whereKey($documentIntake->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();

            if (! $lockedActor->isActive()) {
                throw new AuthorizationException;
            }

            Gate::forUser($lockedActor)->authorize('verify', $lockedIntake);

            if ($lockedIntake->document_type !== IntakeDocumentType::PurchaseQuotation) {
                throw ValidationException::withMessages([
                    'verification' => 'Only a purchase quotation may create a purchase request draft.',
                ]);
            }

            if ($lockedIntake->status === DocumentIntakeStatus::Verified
                && $lockedIntake->purchase_request_id !== null) {
                return PurchaseRequest::query()->findOrFail($lockedIntake->purchase_request_id)
                    ->load(['items', 'attachments', 'requester', 'department', 'category', 'vendor']);
            }

            if ($lockedIntake->status !== DocumentIntakeStatus::NeedsVerification
                || $lockedIntake->purchase_request_id !== null
                || $lockedIntake->purchase_request_attachment_id !== null) {
                throw ValidationException::withMessages([
                    'verification' => 'This quotation is no longer available for verification.',
                ]);
            }

            $this->validatePrivateMetadata($lockedIntake);

            if ($lockedIntake->disk !== $sourceDisk || $lockedIntake->path !== $sourcePath) {
                throw ValidationException::withMessages([
                    'verification' => 'The original quotation changed during verification. Reload and try again.',
                ]);
            }

            $purchaseRequest = $this->purchaseRequests->create($attributes, $lockedActor);
            $attachment = $this->createPurchaseRequestAttachment($lockedIntake, $purchaseRequest, $lockedActor);

            $lockedIntake->forceFill([
                'status' => DocumentIntakeStatus::Verified,
                'purchase_request_id' => $purchaseRequest->id,
                'purchase_request_attachment_id' => $attachment->id,
                'verified_by' => $lockedActor->id,
                'verified_at' => now(),
                'processing_started_at' => null,
                'failure_reason' => null,
            ])->save();

            $this->auditService->logCreated($purchaseRequest, $lockedActor, [
                'request_no' => $purchaseRequest->request_no,
                'department_id' => $purchaseRequest->department_id,
                'vendor_id' => $purchaseRequest->vendor_id,
                'category_id' => $purchaseRequest->category_id,
                'subtotal' => $purchaseRequest->subtotal,
                'tax_amount' => $purchaseRequest->tax_amount,
                'total_amount' => $purchaseRequest->total_amount,
                'status' => $purchaseRequest->status,
            ], metadata: [
                'document_intake_id' => $lockedIntake->id,
                'ai_interaction_id' => $lockedIntake->ai_interaction_id,
            ]);
            $this->auditService->logStatusChange(
                $lockedIntake,
                $lockedActor,
                DocumentIntakeStatus::NeedsVerification,
                DocumentIntakeStatus::Verified,
                metadata: ['purchase_request_id' => $purchaseRequest->id],
            );

            return $purchaseRequest->load(['items', 'attachments', 'requester', 'department', 'category', 'vendor']);
        }, 5);
    }

    private function createPurchaseRequestAttachment(
        DocumentIntake $intake,
        PurchaseRequest $purchaseRequest,
        User $actor,
    ): Attachment {
        $attachment = new Attachment([
            'original_name' => basename($intake->original_name),
            'stored_name' => basename($intake->path),
            'disk' => $intake->disk,
            'path' => $intake->path,
            'mime_type' => $intake->mime_type,
            'size' => $intake->size,
            'uploaded_by' => $actor->id,
        ]);
        $attachment->attachable()->associate($purchaseRequest);
        $attachment->save();

        return $attachment;
    }

    private function validatePrivateSource(DocumentIntake $intake): void
    {
        $this->validatePrivateMetadata($intake);

        if (! Storage::disk($intake->disk)->exists($intake->path)) {
            throw ValidationException::withMessages([
                'verification' => 'The original quotation is unavailable. Restore it before verification.',
            ]);
        }
    }

    private function validatePrivateMetadata(DocumentIntake $intake): void
    {
        if ($intake->disk === ''
            || $intake->disk === 'public'
            || config("filesystems.disks.{$intake->disk}.visibility") === 'public'
            || ! Str::startsWith($intake->path, 'document-intakes/')
            || Str::contains($intake->path, ['../', '..\\'])) {
            throw new LogicException('The quotation source must remain on private document storage.');
        }
    }
}
