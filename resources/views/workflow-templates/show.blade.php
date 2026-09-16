@extends('layouts.app')
@section('title', $workflowTemplate->name)
@section('content')
@php
    $published = $workflowTemplate->versions->firstWhere('status', App\WorkflowVersionStatus::Published);
    $drafts = $workflowTemplate->versions->where('status', App\WorkflowVersionStatus::Draft);
    $archived = $workflowTemplate->versions->where('status', App\WorkflowVersionStatus::Archived);
@endphp

<div class="workflow-breadcrumb"><a href="{{ route('workflow-templates.index') }}">Workflows</a><span>/</span><span>{{ $workflowTemplate->name }}</span></div>
<x-page-header :title="$workflowTemplate->name" :description="$workflowTemplate->module_type->label().' · '.$workflowTemplate->code">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('workflow-templates.edit', $workflowTemplate) }}">Edit template</a>
        <form method="POST" action="{{ route('workflow-versions.store', $workflowTemplate) }}">@csrf<button class="btn btn-primary" type="submit">New blank draft</button></form>
    </x-slot:actions>
</x-page-header>

<section class="card workflow-overview-card"><div class="card-body">
    <div><span class="workflow-module-label">Workflow purpose</span><p>{{ $workflowTemplate->description ?: 'No description has been provided for this workflow.' }}</p></div>
    <dl><div><dt>Template status</dt><dd><x-status-badge :status="$workflowTemplate->status" /></dd></div><div><dt>Published version</dt><dd>{{ $published ? 'Version '.$published->version : 'None' }}</dd></div><div><dt>Draft versions</dt><dd>{{ $drafts->count() }}</dd></div></dl>
</div></section>

<div class="workflow-version-sections">
    <section aria-labelledby="published-version-heading">
        <div class="section-heading-row"><div><h2 id="published-version-heading">Published version</h2><p>The version used for new submissions.</p></div></div>
        @if ($published)
            <article class="card workflow-version-card workflow-version-published"><div class="card-body">
                <div class="workflow-version-identity"><span class="workflow-version-number">V{{ $published->version }}</span><div><h3>Version {{ $published->version }}</h3><p>Published {{ $published->published_at?->format('j M Y, H:i') }} by {{ $published->publisher?->name ?? 'Unknown' }}</p></div></div>
                <x-status-badge :status="$published->status" />
                <div class="workflow-version-actions"><a class="btn btn-sm btn-outline-primary" href="{{ route('workflow-versions.edit', $published) }}">View configuration</a><form method="POST" action="{{ route('workflow-versions.clone', $published) }}">@csrf<button class="btn btn-sm btn-outline-secondary" type="submit">Clone to draft</button></form></div>
            </div></article>
        @else
            <div class="card"><x-empty-state title="No published version" description="Configure and publish a draft before this workflow can route new submissions." /></div>
        @endif
    </section>

    <section aria-labelledby="draft-versions-heading">
        <div class="section-heading-row"><div><h2 id="draft-versions-heading">Draft versions</h2><p>Editable configurations that do not yet affect submissions.</p></div></div>
        <div class="workflow-version-list">
            @forelse ($drafts as $version)
                <article class="card workflow-version-card"><div class="card-body">
                    <div class="workflow-version-identity"><span class="workflow-version-number">V{{ $version->version }}</span><div><h3>Version {{ $version->version }}</h3><p>Created {{ $version->created_at->format('j M Y') }} by {{ $version->creator->name }}</p></div></div>
                    <x-status-badge :status="$version->status" />
                    <div class="workflow-version-actions"><a class="btn btn-sm btn-primary" href="{{ route('workflow-versions.edit', $version) }}">Configure draft</a><form method="POST" action="{{ route('workflow-versions.destroy', $version) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" type="submit">Delete draft</button></form></div>
                </div></article>
            @empty
                <div class="card"><x-empty-state title="No draft versions" description="Create a blank draft or clone the published version to make changes." /></div>
            @endforelse
        </div>
    </section>

    @if ($archived->isNotEmpty())
        <section aria-labelledby="archived-versions-heading">
            <div class="section-heading-row"><div><h2 id="archived-versions-heading">Archived versions</h2><p>Immutable versions retained for approval history.</p></div></div>
            <div class="workflow-version-list">
                @foreach ($archived as $version)
                    <article class="card workflow-version-card workflow-version-archived"><div class="card-body"><div class="workflow-version-identity"><span class="workflow-version-number">V{{ $version->version }}</span><div><h3>Version {{ $version->version }}</h3><p>Created by {{ $version->creator->name }}@if ($version->published_at) · Published {{ $version->published_at->format('j M Y') }}@endif</p></div></div><x-status-badge :status="$version->status" /><div class="workflow-version-actions"><a class="btn btn-sm btn-outline-secondary" href="{{ route('workflow-versions.edit', $version) }}">View configuration</a></div></div></article>
                @endforeach
            </div>
        </section>
    @endif
</div>
@endsection
