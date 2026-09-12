<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkflowLifecycleActionRequest;
use App\Models\SupplierInvoice;
use App\Services\ApprovalService;
use App\Services\SupplierInvoiceService;
use Illuminate\Http\RedirectResponse;

class SupplierInvoiceLifecycleController extends Controller
{
    public function resubmit(
        StoreWorkflowLifecycleActionRequest $request,
        SupplierInvoice $supplierInvoice,
        SupplierInvoiceService $service,
    ): RedirectResponse {
        $service->resubmit($supplierInvoice, $request->user(), $request->validated('comment'));

        return redirect()->route('supplier-invoices.show', $supplierInvoice)
            ->with('success', 'Supplier invoice resubmitted for approval.');
    }

    public function withdraw(
        StoreWorkflowLifecycleActionRequest $request,
        SupplierInvoice $supplierInvoice,
        ApprovalService $service,
    ): RedirectResponse {
        $service->withdraw($supplierInvoice, $request->user(), $request->validated('comment'));

        return redirect()->route('supplier-invoices.show', $supplierInvoice)
            ->with('success', 'Supplier invoice withdrawn.');
    }
}
