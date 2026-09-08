<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveWorkflowRuleGroupRequest;
use App\Models\WorkflowRuleGroup;
use App\Models\WorkflowVersion;
use App\Services\WorkflowConfigurationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WorkflowRuleGroupController extends Controller
{
    public function store(SaveWorkflowRuleGroupRequest $request, WorkflowVersion $workflowVersion, WorkflowConfigurationService $workflows): RedirectResponse
    {
        $workflows->saveRuleGroup($workflowVersion, $request->validated(), $request->user());

        return back()->with('success', 'Rule group added.');
    }

    public function update(SaveWorkflowRuleGroupRequest $request, WorkflowRuleGroup $workflowRuleGroup, WorkflowConfigurationService $workflows): RedirectResponse
    {
        $workflows->saveRuleGroup($workflowRuleGroup->version, $request->validated(), $request->user(), $workflowRuleGroup);

        return back()->with('success', 'Rule group updated.');
    }

    public function destroy(Request $request, WorkflowRuleGroup $workflowRuleGroup, WorkflowConfigurationService $workflows): RedirectResponse
    {
        Gate::authorize('delete', $workflowRuleGroup);
        $workflows->deleteRuleGroup($workflowRuleGroup, $request->user());

        return back()->with('success', 'Rule group deleted.');
    }
}
