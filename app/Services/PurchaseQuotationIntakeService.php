<?php

namespace App\Services;

use App\IntakeDocumentType;
use App\Models\IntakeBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class PurchaseQuotationIntakeService
{
    public function __construct(private readonly DocumentIntakeUploadService $uploads) {}

    /** @param list<UploadedFile> $files */
    public function createBatch(array $files, string $submissionKey, User $uploader): IntakeBatch
    {
        return $this->uploads->createBatch(
            $files,
            $submissionKey,
            $uploader,
            IntakeDocumentType::PurchaseQuotation,
        );
    }
}
