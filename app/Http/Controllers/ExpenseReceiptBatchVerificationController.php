<?php

namespace App\Http\Controllers;

use App\Http\Requests\VerifyExpenseReceiptBatchRequest;
use App\IntakeDocumentType;
use App\MasterDataStatus;
use App\Models\IntakeBatch;
use App\Models\SpendCategory;
use App\Services\ExpenseClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ExpenseReceiptBatchVerificationController extends Controller
{
    public function create(IntakeBatch $intakeBatch): View|RedirectResponse
    {
        Gate::authorize('view', $intakeBatch);
        $this->loadReceiptBatch($intakeBatch);

        if ($intakeBatch->expense_claim_id !== null) {
            return redirect()->route('expense-receipt-intakes.show', $intakeBatch);
        }

        return view('expense-receipt-intakes.verify', [
            'intakeBatch' => $intakeBatch,
            'categories' => SpendCategory::query()
                ->where('status', MasterDataStatus::Active->value)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(
        VerifyExpenseReceiptBatchRequest $request,
        IntakeBatch $intakeBatch,
        ExpenseClaimService $service,
    ): RedirectResponse {
        $claim = $service->createFromReceiptBatch($intakeBatch, $request->validated(), $request->user());

        return redirect()->route('expense-receipt-intakes.show', $intakeBatch)
            ->with('success', "Expense claim {$claim->claim_no} was created as a draft.");
    }

    private function loadReceiptBatch(IntakeBatch $batch): void
    {
        $batch->load(['uploadedBy.department', 'documentIntakes', 'expenseClaim']);
        abort_unless($batch->documentIntakes->isNotEmpty()
            && $batch->documentIntakes->every(
                fn ($document): bool => $document->document_type === IntakeDocumentType::ExpenseReceipt,
            ), 404);
    }
}
