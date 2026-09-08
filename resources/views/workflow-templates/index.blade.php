@extends('layouts.app')
@section('title', 'Workflows')
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h1 class="h2 mb-1">Workflow configuration</h1><p class="text-secondary mb-0">Manage versioned approval policies for each spend module.</p></div><a class="btn btn-primary" href="{{ route('workflow-templates.create') }}">Add workflow</a></div>
<form class="row g-2 mb-4" method="GET"><div class="col-md-5"><label class="visually-hidden" for="search">Search workflows</label><input class="form-control" id="search" name="search" value="{{ $search }}" placeholder="Search name or code"></div><div class="col-auto"><button class="btn btn-outline-secondary" type="submit">Search</button></div></form>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Workflow</th><th>Module</th><th>Status</th><th>Current version</th><th class="text-end">Action</th></tr></thead><tbody>
@forelse ($templates as $template)
<tr><td><div class="fw-semibold">{{ $template->name }}</div><small class="text-secondary">{{ $template->code }}</small></td><td>{{ $template->module_type->label() }}</td><td><span class="badge {{ $template->status === App\MasterDataStatus::Active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ ucfirst(strtolower($template->status->value)) }}</span></td><td>@php($published = $template->versions->firstWhere('status', App\WorkflowVersionStatus::Published)){{ $published ? 'Version '.$published->version : 'Not published' }}</td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('workflow-templates.show', $template) }}">Manage</a></td></tr>
@empty<tr><td class="text-center text-secondary py-5" colspan="5">No workflow templates found.</td></tr>@endforelse
</tbody></table></div></div><div class="mt-3">{{ $templates->links() }}</div>
@endsection
