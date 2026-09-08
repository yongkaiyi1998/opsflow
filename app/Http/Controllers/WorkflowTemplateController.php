<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkflowTemplateRequest;
use App\Http\Requests\UpdateWorkflowTemplateRequest;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowConfigurationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class WorkflowTemplateController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', WorkflowTemplate::class);
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = $request->string('search')->trim()->toString();
        $templates = WorkflowTemplate::query()
            ->with('versions')
            ->when($search, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('workflow-templates.index', compact('templates', 'search'));
    }

    public function create(): View
    {
        Gate::authorize('create', WorkflowTemplate::class);

        return view('workflow-templates.create');
    }

    public function store(StoreWorkflowTemplateRequest $request, WorkflowConfigurationService $workflows): RedirectResponse
    {
        $template = $workflows->createTemplate($request->validated(), $request->user());

        return redirect()->route('workflow-templates.show', $template)->with('success', 'Workflow template created with Version 1 draft.');
    }

    public function show(WorkflowTemplate $workflowTemplate): View
    {
        Gate::authorize('view', $workflowTemplate);
        $workflowTemplate->load(['versions.creator', 'versions.publisher']);

        return view('workflow-templates.show', compact('workflowTemplate'));
    }

    public function edit(WorkflowTemplate $workflowTemplate): View
    {
        Gate::authorize('update', $workflowTemplate);

        return view('workflow-templates.edit', compact('workflowTemplate'));
    }

    public function update(UpdateWorkflowTemplateRequest $request, WorkflowTemplate $workflowTemplate): RedirectResponse
    {
        $workflowTemplate->update($request->validated());

        return redirect()->route('workflow-templates.show', $workflowTemplate)->with('success', 'Workflow template updated.');
    }
}
