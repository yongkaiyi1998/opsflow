<?php

namespace App\Http\Controllers;

use App\Exceptions\AI\AiException;
use App\Http\Requests\AssistApprovalCommentRequest;
use App\Http\Requests\AssistExpenseClaimWritingRequest;
use App\Http\Requests\AssistPurchaseRequestWritingRequest;
use App\Models\ApprovalAssignment;
use App\Models\ExpenseClaim;
use App\Models\PurchaseRequest;
use App\Services\WritingAssistantService;
use Illuminate\Http\RedirectResponse;
use JsonException;

class WritingAssistantController extends Controller
{
    public function purchaseRequest(AssistPurchaseRequestWritingRequest $request, WritingAssistantService $service, ?PurchaseRequest $purchaseRequest = null): RedirectResponse
    {
        try {
            $draft = $service->purchaseRequest($request->validated(), $request->user(), $purchaseRequest);
        } catch (AiException|JsonException|\LogicException) {
            return back()->withInput()->with('writingAssistanceError', 'Writing assistance is unavailable. You can continue editing normally.');
        }

        return back()->withInput()->with('writingAssistance', ['draft_text' => $draft->draftText, 'target' => 'description', 'label' => 'Suggested business justification']);
    }

    public function expenseClaim(AssistExpenseClaimWritingRequest $request, WritingAssistantService $service, ?ExpenseClaim $expenseClaim = null): RedirectResponse
    {
        try {
            $draft = $service->expenseClaim($request->validated(), $request->user(), $expenseClaim);
        } catch (AiException|JsonException|\LogicException) {
            return back()->withInput()->with('writingAssistanceError', 'Writing assistance is unavailable. You can continue editing normally.');
        }

        return back()->withInput()->with('writingAssistance', ['draft_text' => $draft->draftText, 'target' => 'description', 'label' => 'Suggested claim justification']);
    }

    public function approvalComment(AssistApprovalCommentRequest $request, ApprovalAssignment $approvalAssignment, string $writingAction, WritingAssistantService $service): RedirectResponse
    {
        try {
            $draft = $service->approvalComment($approvalAssignment, $request->user(), $writingAction, $request->validated('comment'));
        } catch (AiException|JsonException|\LogicException) {
            return back()->withInput()->with('writingAssistanceError', 'Writing assistance is unavailable. You can continue with the approval action normally.');
        }
        $target = $writingAction === 'request_changes' ? 'changes-comment' : 'reject-comment';

        return back()->withInput()->with('writingAssistance', ['draft_text' => $draft->draftText, 'target' => $target, 'label' => $writingAction === 'request_changes' ? 'Suggested changes comment' : 'Suggested rejection comment']);
    }
}
