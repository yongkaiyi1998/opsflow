<?php

namespace App\Http\Controllers;

use App\Models\PurchaseRequest;
use App\Services\AttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class PurchaseRequestAttachmentController extends Controller
{
    public function store(Request $request, PurchaseRequest $purchaseRequest, AttachmentService $attachments): RedirectResponse
    {
        $request->validate(['attachment' => ['required', 'file']]);
        $file = $request->file('attachment');

        if (! $file instanceof UploadedFile) {
            abort(422);
        }

        $attachments->store($purchaseRequest, $file, $request->user());

        return back()->with('success', 'Attachment uploaded.');
    }
}
