<?php

namespace App\Services;

use App\DocumentIntakeStatus;
use App\IntakeDocumentType;
use App\Models\IntakeBatch;
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

class InvoiceIntakeService
{
    public function __construct(private readonly DocumentExtractionService $extractions) {}

    /** @param list<UploadedFile> $files */
    public function createBatch(array $files, string $submissionKey, User $uploader): IntakeBatch
    {
        Gate::forUser($uploader)->authorize('create', IntakeBatch::class);

        $existing = $this->existingBatch($uploader, $submissionKey);

        if ($existing !== null) {
            return $existing->load(['uploadedBy', 'documentIntakes']);
        }

        $this->validateUploadSet($files);

        $disk = (string) config('document_intake.disk');
        $this->ensurePrivateDisk($disk);
        $storedDocuments = [];

        try {
            foreach ($files as $index => $file) {
                $storedDocuments[] = $this->storeDocument($file, $disk, $index);
            }

            [$batch, $created] = DB::transaction(function () use ($storedDocuments, $submissionKey, $uploader): array {
                $lockedUploader = $this->lockAuthorizedUploader($uploader);
                $batch = IntakeBatch::firstOrCreate([
                    'uploaded_by' => $lockedUploader->id,
                    'submission_key' => $submissionKey,
                ]);

                if ($batch->wasRecentlyCreated) {
                    $batch->documentIntakes()->createMany($storedDocuments);
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

    private function existingBatch(User $uploader, string $submissionKey): ?IntakeBatch
    {
        return DB::transaction(function () use ($uploader, $submissionKey): ?IntakeBatch {
            $lockedUploader = $this->lockAuthorizedUploader($uploader);

            return IntakeBatch::query()
                ->where('uploaded_by', $lockedUploader->id)
                ->where('submission_key', $submissionKey)
                ->first();
        }, 5);
    }

    /** @param list<UploadedFile> $files */
    private function validateUploadSet(array $files): void
    {
        $maxFiles = (int) config('document_intake.max_files', 20);

        if ($files === [] || count($files) > $maxFiles) {
            throw ValidationException::withMessages([
                'documents' => "Select between 1 and {$maxFiles} invoice documents.",
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

    private function lockAuthorizedUploader(User $uploader): User
    {
        $lockedUploader = User::query()->whereKey($uploader->id)->lockForUpdate()->firstOrFail();
        Gate::forUser($lockedUploader)->authorize('create', IntakeBatch::class);

        return $lockedUploader;
    }

    /** @return array<string, int|string> */
    private function storeDocument(UploadedFile $file, string $disk, int $index): array
    {
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
            throw new RuntimeException('The invoice document could not be stored.');
        }

        return [
            'original_name' => $this->safeOriginalName($file, $extension),
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $mimeType,
            'size' => (int) $file->getSize(),
            'document_type' => IntakeDocumentType::SupplierInvoice->value,
            'status' => DocumentIntakeStatus::Pending->value,
        ];
    }

    private function safeOriginalName(UploadedFile $file, string $extension): string
    {
        $name = str_replace('\\', '/', $file->getClientOriginalName());
        $name = Str::afterLast($name, '/');
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        return Str::limit($name !== '' ? $name : "invoice.{$extension}", 255, '');
    }

    /** @param list<array<string, int|string>> $storedDocuments */
    private function deleteStoredDocuments(string $disk, array $storedDocuments): void
    {
        Storage::disk($disk)->delete(array_column($storedDocuments, 'path'));
    }

    private function ensurePrivateDisk(string $disk): void
    {
        if ($disk === '' || $disk === 'public' || config("filesystems.disks.{$disk}.visibility") === 'public') {
            throw new LogicException('Invoice intake documents must use a private filesystem disk.');
        }
    }
}
