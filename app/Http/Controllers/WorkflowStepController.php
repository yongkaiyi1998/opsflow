<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveWorkflowStepRequest;
use App\Models\WorkflowRuleGroup;
use App\Models\WorkflowStep;
use App\Services\WorkflowConfigurationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WorkflowStepController extends Controller
{
    public function store(SaveWorkflowStepRequest $request, WorkflowRuleGroup $workflowRuleGroup, WorkflowConfigurationService $workflows): RedirectResponse
    {
        $workflows->saveStep($workflowRuleGroup, $request->configuration(), $request->user());

        return back()->with('success', 'Approval step added.');
    }

    public function update(SaveWorkflowStepRequest $request, WorkflowStep $workflowStep, WorkflowConfigurationService $workflows): RedirectResponse
    {
        $workflows->saveStep($workflowStep->ruleGroup, $request->configuration(), $request->user(), $workflowStep);

        return back()->with('success', 'Approval step updated.');
    }

    public function destroy(Request $request, WorkflowStep $workflowStep, WorkflowConfigurationService $workflows): RedirectResponse
    {
        Gate::authorize('delete', $workflowStep);
        $workflows->deleteStep($workflowStep, $request->user());

        return back()->with('success', 'Approval step deleted.');
    }
}
