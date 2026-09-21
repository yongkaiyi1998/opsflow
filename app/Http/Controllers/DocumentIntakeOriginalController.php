<?php

namespace App\Http\Controllers;

use App\IntakeDocumentType;
use App\Models\DocumentIntake;
use App\Models\IntakeBatch;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentIntakeOriginalController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(IntakeBatch $intakeBatch, DocumentIntake $documentIntake): StreamedResponse
    {
        Gate::authorize('view', $intakeBatch);
        Gate::authorize('view', $documentIntake);
        abort_unless($documentIntake->intake_batch_id === $intakeBatch->id, 404);
        $this->authorizeRouteType($documentIntake);
        abort_if(
            $documentIntake->disk === 'public'
                || config("filesystems.disks.{$documentIntake->disk}.visibility") === 'public',
            404,
        );
        abort_unless(
            Str::startsWith($documentIntake->path, 'document-intakes/')
                && ! Str::contains($documentIntake->path, ['../', '..\\']),
            404,
        );
        abort_unless(Storage::disk($documentIntake->disk)->exists($documentIntake->path), 404);

        return Storage::disk($documentIntake->disk)->download(
            $documentIntake->path,
            $documentIntake->original_name,
        );
    }

    private function authorizeRouteType(DocumentIntake $documentIntake): void
    {
        if (request()->routeIs('expense-receipt-intakes.*')) {
            abort_unless($documentIntake->document_type === IntakeDocumentType::ExpenseReceipt, 404);

            return;
        }

        if (request()->routeIs('purchase-quotation-intakes.*')) {
            abort_unless($documentIntake->document_type === IntakeDocumentType::PurchaseQuotation, 404);

            return;
        }

        abort_unless($documentIntake->document_type === IntakeDocumentType::SupplierInvoice, 404);
    }
}
