@extends('layouts.app')
@section('title', 'Workflows')
@section('content')
<x-page-header title="Workflow configuration" description="Manage versioned approval policies for each spend module.">
    <x-slot:actions><a class="btn btn-primary" href="{{ route('workflow-templates.create') }}">Add workflow</a></x-slot:actions>
</x-page-header>
<form class="filter-panel" method="GET"><div class="row g-2 align-items-center"><div class="col-lg-6"><label class="visually-hidden" for="search">Search workflows</label><input class="form-control" id="search" name="search" value="{{ $search }}" placeholder="Search name or code"></div><div class="col-md-auto"><button class="btn btn-outline-secondary" type="submit">Search</button></div>@if ($search)<div class="col-md-auto"><a class="btn btn-link" href="{{ route('workflow-templates.index') }}">Clear</a></div>@endif</div></form>
<div class="workflow-template-grid">
    @forelse ($templates as $template)
        @php
            $published = $template->versions->firstWhere('status', App\WorkflowVersionStatus::Published);
            $draftCount = $template->versions->where('status', App\WorkflowVersionStatus::Draft)->count();
        @endphp
        <article class="card workflow-template-card">
            <div class="card-body">
                <div class="workflow-template-card-header"><span class="workflow-module-label">{{ $template->module_type->label() }}</span><x-status-badge :status="$template->status" /></div>
                <h2><a href="{{ route('workflow-templates.show', $template) }}">{{ $template->name }}</a></h2>
                <code>{{ $template->code }}</code>
                <p>{{ $template->description ?: 'No workflow description provided.' }}</p>
                <div class="workflow-version-summary">
                    <div><span>Published</span><strong>{{ $published ? 'Version '.$published->version : 'None' }}</strong></div>
                    <div><span>Drafts</span><strong>{{ $draftCount }}</strong></div>
                    <div><span>Total versions</span><strong>{{ $template->versions->count() }}</strong></div>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('workflow-templates.show', $template) }}">Manage workflow</a>
            </div>
        </article>
    @empty
        <div class="card grid-column-full"><x-empty-state :title="$search ? 'No matching workflows' : 'No workflows yet'" :description="$search ? 'Try a different workflow name or code.' : 'Add the first workflow template to configure approval routing.'" /></div>
    @endforelse
</div>
<div class="mt-3">{{ $templates->links() }}</div>
@endsection
