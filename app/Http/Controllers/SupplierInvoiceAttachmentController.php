<?php

namespace App\Http\Controllers;

use App\Models\SupplierInvoice;
use App\Services\AttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class SupplierInvoiceAttachmentController extends Controller
{
    public function store(Request $request, SupplierInvoice $supplierInvoice, AttachmentService $attachments): RedirectResponse
    {
        $request->validate(['attachment' => ['required', 'file']]);
        $file = $request->file('attachment');

        if (! $file instanceof UploadedFile) {
            abort(422);
        }

        $attachments->store($supplierInvoice, $file, $request->user());

        return back()->with('success', 'Invoice attachment uploaded.');
    }
}
