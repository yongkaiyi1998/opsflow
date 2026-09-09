<?php

namespace App\Http\Controllers;

use App\Models\SupplierInvoice;
use App\Services\SupplierInvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SupplierInvoiceSubmissionController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, SupplierInvoice $supplierInvoice, SupplierInvoiceService $service): RedirectResponse
    {
        $service->submit($supplierInvoice, $request->user());

        return redirect()->route('supplier-invoices.show', $supplierInvoice)->with('success', 'Supplier invoice submitted for approval.');
    }
}
