<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveWorkflowRuleRequest;
use App\Models\WorkflowRule;
use App\Models\WorkflowRuleGroup;
use App\Services\WorkflowConfigurationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WorkflowRuleController extends Controller
{
    public function store(SaveWorkflowRuleRequest $request, WorkflowRuleGroup $workflowRuleGroup, WorkflowConfigurationService $workflows): RedirectResponse
    {
        $workflows->saveRule($workflowRuleGroup, $request->configuration(), $request->user());

        return back()->with('success', 'Rule added.');
    }

    public function update(SaveWorkflowRuleRequest $request, WorkflowRule $workflowRule, WorkflowConfigurationService $workflows): RedirectResponse
    {
        $workflows->saveRule($workflowRule->ruleGroup, $request->configuration(), $request->user(), $workflowRule);

        return back()->with('success', 'Rule updated.');
    }

    public function destroy(Request $request, WorkflowRule $workflowRule, WorkflowConfigurationService $workflows): RedirectResponse
    {
        Gate::authorize('delete', $workflowRule);
        $workflows->deleteRule($workflowRule, $request->user());

        return back()->with('success', 'Rule deleted.');
    }
}
