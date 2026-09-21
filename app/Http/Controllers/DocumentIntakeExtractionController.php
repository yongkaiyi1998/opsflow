<?php

namespace App\Http\Controllers;

use App\DocumentIntakeStatus;
use App\IntakeDocumentType;
use App\Models\DocumentIntake;
use App\Models\IntakeBatch;
use App\Services\DocumentExtractionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class DocumentIntakeExtractionController extends Controller
{
    public function __invoke(
        IntakeBatch $intakeBatch,
        DocumentIntake $documentIntake,
        DocumentExtractionService $extraction,
    ): RedirectResponse {
        Gate::authorize('view', $intakeBatch);
        Gate::authorize('process', $documentIntake);
        abort_unless($documentIntake->intakeBatch()->whereKey($intakeBatch->getKey())->exists(), 404);
        $expectedType = match (true) {
            request()->routeIs('expense-receipt-intakes.*') => IntakeDocumentType::ExpenseReceipt,
            request()->routeIs('purchase-quotation-intakes.*') => IntakeDocumentType::PurchaseQuotation,
            default => IntakeDocumentType::SupplierInvoice,
        };
        abort_unless($documentIntake->document_type === $expectedType, 404);

        if (! config('ai.enabled')) {
            return back()->with('error', 'AI processing is disabled. The document remains pending.');
        }

        if (! in_array($documentIntake->status, [
            DocumentIntakeStatus::Pending,
            DocumentIntakeStatus::Processing,
            DocumentIntakeStatus::Failed,
        ], true)) {
            return back()->with('error', 'This document no longer requires extraction.');
        }

        $extraction->dispatch($documentIntake);

        return back()->with('success', $documentIntake->status === DocumentIntakeStatus::Failed
            ? 'Document extraction retry queued.'
            : 'Document extraction queued.');
    }
}
