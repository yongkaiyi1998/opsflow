<?php

namespace App\Http\Controllers;

use App\DocumentIntakeStatus;
use App\Http\Requests\VerifyPurchaseQuotationRequest;
use App\IntakeDocumentType;
use App\MasterDataStatus;
use App\Models\DocumentIntake;
use App\Models\IntakeBatch;
use App\Models\SpendCategory;
use App\Models\Vendor;
use App\Services\PurchaseQuotationVerificationService;
use App\Services\VendorMatchingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PurchaseQuotationVerificationController extends Controller
{
    public function create(
        IntakeBatch $intakeBatch,
        DocumentIntake $documentIntake,
        VendorMatchingService $vendorMatching,
    ): View|RedirectResponse {
        $this->authorizeQuotation($intakeBatch, $documentIntake);
        $documentIntake->load('purchaseRequest');

        if ($documentIntake->status === DocumentIntakeStatus::Verified
            && $documentIntake->purchase_request_id !== null) {
            return redirect()->route('purchase-quotation-intakes.documents.show', [$intakeBatch, $documentIntake]);
        }

        abort_unless($documentIntake->status === DocumentIntakeStatus::NeedsVerification, 404);
        $candidate = is_array($documentIntake->extraction_payload) ? $documentIntake->extraction_payload : [];

        return view('purchase-quotation-intakes.documents.verify', [
            'intakeBatch' => $intakeBatch,
            'documentIntake' => $documentIntake,
            'candidate' => $candidate,
            'vendorMatches' => $vendorMatching->match($candidate['vendor_name'] ?? null),
            'vendors' => Vendor::query()->where('status', MasterDataStatus::Active->value)->orderBy('name')->get(),
            'categories' => SpendCategory::query()->where('status', MasterDataStatus::Active->value)->orderBy('name')->get(),
        ]);
    }

    public function store(
        VerifyPurchaseQuotationRequest $request,
        IntakeBatch $intakeBatch,
        DocumentIntake $documentIntake,
        PurchaseQuotationVerificationService $verification,
    ): RedirectResponse {
        abort_unless($documentIntake->intake_batch_id === $intakeBatch->id, 404);
        abort_unless($documentIntake->document_type === IntakeDocumentType::PurchaseQuotation, 404);

        $purchaseRequest = $verification->verify($documentIntake, $request->validated(), $request->user());

        return redirect()
            ->route('purchase-quotation-intakes.documents.show', [$intakeBatch, $documentIntake])
            ->with('success', "Purchase request {$purchaseRequest->request_no} was created as a draft. It has not been submitted for approval.");
    }

    private function authorizeQuotation(IntakeBatch $batch, DocumentIntake $document): void
    {
        Gate::authorize('view', $batch);
        Gate::authorize('verify', $document);
        abort_unless($document->intake_batch_id === $batch->id, 404);
        abort_unless($document->document_type === IntakeDocumentType::PurchaseQuotation, 404);
    }
}
