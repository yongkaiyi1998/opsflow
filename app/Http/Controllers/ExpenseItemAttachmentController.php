<?php

namespace App\Http\Controllers;

use App\Models\ExpenseItem;
use App\Services\AttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class ExpenseItemAttachmentController extends Controller
{
    public function store(Request $request, ExpenseItem $expenseItem, AttachmentService $attachments): RedirectResponse
    {
        $request->validate(['attachment' => ['required', 'file']]);
        $file = $request->file('attachment');

        if (! $file instanceof UploadedFile) {
            abort(422);
        }

        $attachments->store($expenseItem, $file, $request->user());

        return back()->with('success', 'Expense receipt uploaded.');
    }
}
