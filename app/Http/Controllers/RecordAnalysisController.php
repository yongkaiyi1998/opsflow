<?php

namespace App\Http\Controllers;

use App\Exceptions\AI\AiException;
use App\Exceptions\AiAnalysisException;
use App\Models\ApprovalAssignment;
use App\Models\ExpenseClaim;
use App\Models\PurchaseRequest;
use App\Models\SupplierInvoice;
use App\Services\RecordAnalysisService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use JsonException;

class RecordAnalysisController extends Controller
{
    public function purchaseRequest(
        Request $request,
        PurchaseRequest $purchaseRequest,
        RecordAnalysisService $analysis,
    ): RedirectResponse {
        Gate::authorize('view', $purchaseRequest);

        return $this->generate($request, $purchaseRequest, $analysis);
    }

    public function supplierInvoice(
        Request $request,
        SupplierInvoice $supplierInvoice,
        RecordAnalysisService $analysis,
    ): RedirectResponse {
        Gate::authorize('view', $supplierInvoice);

        return $this->generate($request, $supplierInvoice, $analysis);
    }

    public function expenseClaim(
        Request $request,
        ExpenseClaim $expenseClaim,
        RecordAnalysisService $analysis,
    ): RedirectResponse {
        Gate::authorize('view', $expenseClaim);

        return $this->generate($request, $expenseClaim, $analysis);
    }

    public function approval(
        Request $request,
        ApprovalAssignment $approvalAssignment,
        RecordAnalysisService $analysis,
    ): RedirectResponse {
        Gate::authorize('view', $approvalAssignment);
        $business = $approvalAssignment->step()->with('approvalInstance.approvable')->firstOrFail()->approvalInstance->approvable;

        if (! $business instanceof PurchaseRequest
            && ! $business instanceof SupplierInvoice
            && ! $business instanceof ExpenseClaim) {
            abort(404);
        }

        return $this->generate($request, $business, $analysis);
    }

    private function generate(
        Request $request,
        PurchaseRequest|SupplierInvoice|ExpenseClaim $record,
        RecordAnalysisService $analysis,
    ): RedirectResponse {
        try {
            $analysis->generate($record, $request->user());
        } catch (AiException|AiAnalysisException|JsonException) {
            return back()->with('aiAnalysisError', 'AI analysis is unavailable. The authoritative record and approval actions remain available.');
        }

        return back()->with('success', 'AI analysis is ready.');
    }
}
