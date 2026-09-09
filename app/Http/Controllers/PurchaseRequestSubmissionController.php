<?php

namespace App\Http\Controllers;

use App\Models\PurchaseRequest;
use App\Services\PurchaseRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PurchaseRequestSubmissionController extends Controller
{
    public function store(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service): RedirectResponse
    {
        $service->submit($purchaseRequest, $request->user());

        return redirect()->route('purchase-requests.show', $purchaseRequest)->with('success', 'Purchase request submitted for approval.');
    }
}
