<?php

namespace App\Http\Controllers;

use App\Exceptions\AI\AiException;
use App\Models\ApprovalAssignment;
use App\Services\WorkflowExplanationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use JsonException;

class WorkflowExplanationController extends Controller
{
    public function __invoke(Request $request, ApprovalAssignment $approvalAssignment, WorkflowExplanationService $service): RedirectResponse
    {
        try {
            $service->generate($approvalAssignment, $request->user());
        } catch (AiException|JsonException|\LogicException) {
            return back()->with('workflowExplanationError', 'AI explanation is unavailable. The authoritative workflow facts remain available.');
        }

        return back()->with('success', 'Workflow explanation is ready.');
    }
}
