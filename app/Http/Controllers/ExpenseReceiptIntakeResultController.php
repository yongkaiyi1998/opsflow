<?php

namespace App\Http\Controllers;

use App\IntakeDocumentType;
use App\Models\DocumentIntake;
use App\Models\IntakeBatch;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ExpenseReceiptIntakeResultController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(IntakeBatch $intakeBatch, DocumentIntake $documentIntake): View
    {
        Gate::authorize('view', $intakeBatch);
        Gate::authorize('view', $documentIntake);
        abort_unless($documentIntake->intake_batch_id === $intakeBatch->id, 404);
        abort_unless($documentIntake->document_type === IntakeDocumentType::ExpenseReceipt, 404);
        $documentIntake->load('aiInteraction');

        return view('expense-receipt-intakes.documents.show', compact('intakeBatch', 'documentIntake'));
    }
}
