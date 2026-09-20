<?php

namespace App\Services;

use App\DocumentIntakeStatus;
use App\Models\Attachment;
use App\Models\DocumentIntake;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class DocumentIntakeVerificationService
{
    public function __construct(
        private readonly SupplierInvoiceService $supplierInvoices,
        private readonly AuditService $auditService,
        private readonly DuplicateDetectionService $duplicateDetection,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function verify(DocumentIntake $documentIntake, array $attributes, User $actor): SupplierInvoice
    {
        Gate::forUser($actor)->authorize('verify', $documentIntake);
        $this->validatePrivateSource($documentIntake);
        $sourceDisk = $documentIntake->disk;
        $sourcePath = $documentIntake->path;

        return DB::transaction(function () use ($documentIntake, $attributes, $actor, $sourceDisk, $sourcePath): SupplierInvoice {
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
            $this->validatePrivateMetadata($lockedIntake);

            if ($lockedIntake->status !== DocumentIntakeStatus::NeedsVerification
                || $lockedIntake->supplier_invoice_id !== null) {
                throw ValidationException::withMessages([
                    'verification' => 'This document is no longer available for verification.',
                ]);
            }

            if ($lockedIntake->disk !== $sourceDisk || $lockedIntake->path !== $sourcePath) {
                throw ValidationException::withMessages([
                    'verification' => 'The original document changed during verification. Reload and try again.',
                ]);
            }

            $exactDuplicate = $this->duplicateDetection->findExactSupplierInvoice(
                (int) ($attributes['vendor_id'] ?? 0),
                (string) ($attributes['invoice_no'] ?? ''),
            );

            if ($exactDuplicate !== null) {
                throw ValidationException::withMessages([
                    'invoice_no' => "This vendor and normalized invoice number already match {$exactDuplicate->internal_no}.",
                ]);
            }

            $invoice = $this->supplierInvoices->create($attributes, $lockedActor);
            $attachment = $this->createInvoiceAttachment($lockedIntake, $invoice);

            $lockedIntake->forceFill([
                'status' => DocumentIntakeStatus::Verified,
                'supplier_invoice_id' => $invoice->id,
                'supplier_invoice_attachment_id' => $attachment->id,
                'verified_by' => $lockedActor->id,
                'verified_at' => now(),
                'processing_started_at' => null,
                'failure_reason' => null,
            ])->save();

            $this->auditService->logCreated($invoice, $lockedActor, [
                'internal_no' => $invoice->internal_no,
                'invoice_no' => $invoice->invoice_no,
                'vendor_id' => $invoice->vendor_id,
                'department_id' => $invoice->department_id,
                'category_id' => $invoice->category_id,
                'subtotal' => $invoice->subtotal,
                'tax_amount' => $invoice->tax_amount,
                'total_amount' => $invoice->total_amount,
                'status' => $invoice->status,
            ], metadata: [
                'document_intake_id' => $lockedIntake->id,
                'ai_interaction_id' => $lockedIntake->ai_interaction_id,
            ]);
            $this->auditService->logStatusChange(
                $lockedIntake,
                $lockedActor,
                DocumentIntakeStatus::NeedsVerification,
                DocumentIntakeStatus::Verified,
                metadata: ['supplier_invoice_id' => $invoice->id],
            );

            return $invoice->load(['items', 'attachments', 'vendor', 'department', 'category', 'submittedBy']);
        }, 5);
    }

    private function createInvoiceAttachment(DocumentIntake $intake, SupplierInvoice $invoice): Attachment
    {
        $attachment = new Attachment([
            'original_name' => basename($intake->original_name),
            'stored_name' => basename($intake->path),
            'disk' => $intake->disk,
            'path' => $intake->path,
            'mime_type' => $intake->mime_type,
            'size' => $intake->size,
            'uploaded_by' => $intake->intakeBatch->uploaded_by,
        ]);
        $attachment->attachable()->associate($invoice);
        $attachment->save();

        return $attachment;
    }

    private function validatePrivateSource(DocumentIntake $intake): void
    {
        $this->validatePrivateMetadata($intake);

        if (! Storage::disk($intake->disk)->exists($intake->path)) {
            throw ValidationException::withMessages([
                'verification' => 'The original document is unavailable. Restore it before verification.',
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
            throw new LogicException('The intake source must remain on private document storage.');
        }
    }
}
