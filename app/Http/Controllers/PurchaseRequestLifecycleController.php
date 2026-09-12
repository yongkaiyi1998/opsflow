<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkflowLifecycleActionRequest;
use App\Models\PurchaseRequest;
use App\Services\ApprovalService;
use App\Services\PurchaseRequestService;
use Illuminate\Http\RedirectResponse;

class PurchaseRequestLifecycleController extends Controller
{
    public function resubmit(
        StoreWorkflowLifecycleActionRequest $request,
        PurchaseRequest $purchaseRequest,
        PurchaseRequestService $service,
    ): RedirectResponse {
        $service->resubmit($purchaseRequest, $request->user(), $request->validated('comment'));

        return redirect()->route('purchase-requests.show', $purchaseRequest)
            ->with('success', 'Purchase request resubmitted for approval.');
    }

    public function withdraw(
        StoreWorkflowLifecycleActionRequest $request,
        PurchaseRequest $purchaseRequest,
        ApprovalService $service,
    ): RedirectResponse {
        $service->withdraw($purchaseRequest, $request->user(), $request->validated('comment'));

        return redirect()->route('purchase-requests.show', $purchaseRequest)
            ->with('success', 'Purchase request withdrawn.');
    }
}
