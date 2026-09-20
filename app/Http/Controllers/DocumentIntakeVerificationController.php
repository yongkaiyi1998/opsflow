<?php

namespace App\Http\Controllers;

use App\Http\Requests\VerifyDocumentIntakeRequest;
use App\Models\DocumentIntake;
use App\Models\IntakeBatch;
use App\Services\DocumentIntakeVerificationService;
use Illuminate\Http\RedirectResponse;

class DocumentIntakeVerificationController extends Controller
{
    public function __invoke(
        VerifyDocumentIntakeRequest $request,
        IntakeBatch $intakeBatch,
        DocumentIntake $documentIntake,
        DocumentIntakeVerificationService $verification,
    ): RedirectResponse {
        abort_unless($documentIntake->intake_batch_id === $intakeBatch->id, 404);

        $invoice = $verification->verify($documentIntake, $request->validated(), $request->user());

        return redirect()
            ->route('invoice-intakes.documents.show', [$intakeBatch, $documentIntake])
            ->with('success', "Supplier invoice {$invoice->internal_no} was created as a draft. It has not been submitted for approval.");
    }
}
