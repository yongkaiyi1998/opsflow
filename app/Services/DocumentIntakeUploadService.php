<?php

namespace App\Services;

use App\DocumentIntakeStatus;
use App\IntakeDocumentType;
use App\Models\ExpenseClaim;
use App\Models\IntakeBatch;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Throwable;

class DocumentIntakeUploadService
{
    public function __construct(private readonly DocumentExtractionService $extractions) {}

    /** @param list<UploadedFile> $files */
    public function createBatch(
        array $files,
        string $submissionKey,
        User $uploader,
        IntakeDocumentType $documentType,
    ): IntakeBatch {
        $this->authorizeUpload($uploader, $documentType);

        $existing = $this->existingBatch($uploader, $submissionKey, $documentType);

        if ($existing !== null) {
            return $existing->load(['uploadedBy', 'documentIntakes']);
        }

        $this->validateUploadSet($files, $documentType);

        $disk = (string) config('document_intake.disk');
        $this->ensurePrivateDisk($disk);
        $storedDocuments = [];

        try {
            foreach ($files as $index => $file) {
                $storedDocuments[] = $this->storeDocument($file, $disk, $index, $documentType);
            }

            [$batch, $created] = DB::transaction(function () use ($storedDocuments, $submissionKey, $uploader, $documentType): array {
                $lockedUploader = $this->lockAuthorizedUploader($uploader, $documentType);
                $batch = IntakeBatch::firstOrCreate([
                    'uploaded_by' => $lockedUploader->id,
                    'submission_key' => $submissionKey,
                ]);

                if ($batch->wasRecentlyCreated) {
                    $batch->documentIntakes()->createMany($storedDocuments);
                } else {
                    $this->ensureBatchType($batch, $documentType);
                }

                return [$batch, $batch->wasRecentlyCreated];
            }, 5);
        } catch (Throwable $exception) {
            $this->deleteStoredDocuments($disk, $storedDocuments);

            throw $exception;
        }

        if (! $created) {
            $this->deleteStoredDocuments($disk, $storedDocuments);
        }

        $batch->load(['uploadedBy', 'documentIntakes']);

        if ($created) {
            foreach ($batch->documentIntakes as $documentIntake) {
                $this->extractions->dispatch($documentIntake);
            }
        }

        return $batch;
    }

    private function existingBatch(
        User $uploader,
        string $submissionKey,
        IntakeDocumentType $documentType,
    ): ?IntakeBatch {
        return DB::transaction(function () use ($uploader, $submissionKey, $documentType): ?IntakeBatch {
            $lockedUploader = $this->lockAuthorizedUploader($uploader, $documentType);
            $batch = IntakeBatch::query()
                ->where('uploaded_by', $lockedUploader->id)
                ->where('submission_key', $submissionKey)
                ->first();

            if ($batch !== null) {
                $this->ensureBatchType($batch, $documentType);
            }

            return $batch;
        }, 5);
    }

    /** @param list<UploadedFile> $files */
    private function validateUploadSet(array $files, IntakeDocumentType $documentType): void
    {
        $maxFiles = (int) config('document_intake.max_files', 20);

        if ($files === [] || count($files) > $maxFiles) {
            $label = match ($documentType) {
                IntakeDocumentType::ExpenseReceipt => 'receipt documents',
                IntakeDocumentType::PurchaseQuotation => 'quotation documents',
                IntakeDocumentType::SupplierInvoice => 'invoice documents',
            };

            throw ValidationException::withMessages([
                'documents' => "Select between 1 and {$maxFiles} {$label}.",
            ]);
        }

        $maxBytes = (int) config('document_intake.extraction_max_bytes', 10 * 1024 * 1024);

        foreach ($files as $index => $file) {
            $size = $file->getSize();

            if (! is_int($size) || $size < 1 || $size > $maxBytes) {
                throw ValidationException::withMessages([
                    "documents.{$index}" => 'Each document must contain data and may not exceed the configured size limit.',
                ]);
            }
        }
    }

    private function lockAuthorizedUploader(User $uploader, IntakeDocumentType $documentType): User
    {
        $lockedUploader = User::query()->whereKey($uploader->id)->lockForUpdate()->firstOrFail();
        $this->authorizeUpload($lockedUploader, $documentType);

        return $lockedUploader;
    }

    private function authorizeUpload(User $uploader, IntakeDocumentType $documentType): void
    {
        match ($documentType) {
            IntakeDocumentType::SupplierInvoice => Gate::forUser($uploader)->authorize('create', IntakeBatch::class),
            IntakeDocumentType::ExpenseReceipt => Gate::forUser($uploader)->authorize('create', ExpenseClaim::class),
            IntakeDocumentType::PurchaseQuotation => Gate::forUser($uploader)->authorize('create', PurchaseRequest::class),
        };
    }

    /** @return array<string, int|string> */
    private function storeDocument(
        UploadedFile $file,
        string $disk,
        int $index,
        IntakeDocumentType $documentType,
    ): array {
        $mimeType = $file->getMimeType() ?: '';
        $extension = config("document_intake.mime_extensions.{$mimeType}");

        if (! is_string($extension)) {
            throw ValidationException::withMessages([
                "documents.{$index}" => 'The document must be a PDF, JPEG, or PNG file.',
            ]);
        }

        $storedName = Str::uuid()->toString().".{$extension}";
        $path = $file->storeAs('document-intakes/'.now()->format('Y/m'), $storedName, $disk);

        if ($path === false || ! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('The intake document could not be stored.');
        }

        return [
            'original_name' => $this->safeOriginalName($file, $extension, $documentType),
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $mimeType,
            'size' => (int) $file->getSize(),
            'document_type' => $documentType->value,
            'status' => DocumentIntakeStatus::Pending->value,
        ];
    }

    private function safeOriginalName(
        UploadedFile $file,
        string $extension,
        IntakeDocumentType $documentType,
    ): string {
        $name = str_replace('\\', '/', $file->getClientOriginalName());
        $name = Str::afterLast($name, '/');
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);
        $fallback = match ($documentType) {
            IntakeDocumentType::ExpenseReceipt => 'receipt',
            IntakeDocumentType::PurchaseQuotation => 'quotation',
            IntakeDocumentType::SupplierInvoice => 'invoice',
        };

        return Str::limit($name !== '' ? $name : "{$fallback}.{$extension}", 255, '');
    }

    private function ensureBatchType(IntakeBatch $batch, IntakeDocumentType $documentType): void
    {
        $hasDifferentType = $batch->documentIntakes()
            ->where('document_type', '!=', $documentType->value)
            ->exists();
        $hasRequestedType = $batch->documentIntakes()
            ->where('document_type', $documentType->value)
            ->exists();

        if ($hasDifferentType || ! $hasRequestedType) {
            throw ValidationException::withMessages([
                'submission_key' => 'This upload submission has already been used for another intake type.',
            ]);
        }
    }

    /** @param list<array<string, int|string>> $storedDocuments */
    private function deleteStoredDocuments(string $disk, array $storedDocuments): void
    {
        Storage::disk($disk)->delete(array_column($storedDocuments, 'path'));
    }

    private function ensurePrivateDisk(string $disk): void
    {
        if ($disk === '' || $disk === 'public' || config("filesystems.disks.{$disk}.visibility") === 'public') {
            throw new LogicException('Document intake files must use a private filesystem disk.');
        }
    }
}
