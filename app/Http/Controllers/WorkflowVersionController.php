<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\SpendCategory;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowVersion;
use App\Services\WorkflowConfigurationService;
use App\UserStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class WorkflowVersionController extends Controller
{
    public function store(Request $request, WorkflowTemplate $workflowTemplate, WorkflowConfigurationService $workflows): RedirectResponse
    {
        Gate::authorize('update', $workflowTemplate);
        $version = $workflows->createDraft($workflowTemplate, $request->user());

        return redirect()->route('workflow-versions.edit', $version)->with('success', "Version {$version->version} draft created.");
    }

    public function edit(WorkflowVersion $workflowVersion): View
    {
        Gate::authorize('view', $workflowVersion);
        $workflowVersion->load(['template', 'ruleGroups.rules', 'ruleGroups.steps']);
        $departments = Department::orderBy('name')->get();
        $categories = SpendCategory::orderBy('name')->get();
        $users = User::where('status', UserStatus::Active->value)->orderBy('name')->get();

        return view('workflow-versions.edit', compact('workflowVersion', 'departments', 'categories', 'users'));
    }

    public function destroy(Request $request, WorkflowVersion $workflowVersion, WorkflowConfigurationService $workflows): RedirectResponse
    {
        Gate::authorize('delete', $workflowVersion);
        $template = $workflowVersion->template;
        $workflows->deleteDraft($workflowVersion, $request->user());

        return redirect()->route('workflow-templates.show', $template)->with('success', 'Draft workflow version deleted.');
    }

    public function publish(Request $request, WorkflowVersion $workflowVersion, WorkflowConfigurationService $workflows): RedirectResponse
    {
        Gate::authorize('publish', $workflowVersion);
        $version = $workflows->publish($workflowVersion, $request->user(), $request);

        return redirect()->route('workflow-templates.show', $version->template)->with('success', "Version {$version->version} published.");
    }

    public function clone(Request $request, WorkflowVersion $workflowVersion, WorkflowConfigurationService $workflows): RedirectResponse
    {
        Gate::authorize('clone', $workflowVersion);
        $clone = $workflows->clonePublished($workflowVersion, $request->user());

        return redirect()->route('workflow-versions.edit', $clone)->with('success', "Version {$clone->version} draft created from the published version.");
    }
}
