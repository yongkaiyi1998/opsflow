@extends('layouts.app')
@section('title', 'Departments')
@section('content')
<x-page-header title="Departments" description="Maintain organization units and the managers used for approval routing.">
    <x-slot:actions><a class="btn btn-primary" href="{{ route('departments.create') }}">Add department</a></x-slot:actions>
</x-page-header>

<form class="filter-panel" method="GET" action="{{ route('departments.index') }}">
    <div class="row g-2 align-items-center">
        <div class="col-lg-6"><label class="visually-hidden" for="search">Search departments</label><input class="form-control" id="search" name="search" value="{{ $search }}" placeholder="Search name or code"></div>
        <div class="col-md-auto"><button class="btn btn-outline-secondary" type="submit">Search</button></div>
        @if ($search)<div class="col-md-auto"><a class="btn btn-link" href="{{ route('departments.index') }}">Clear</a></div>@endif
    </div>
</form>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Name</th><th>Code</th><th>Manager</th><th>Status</th><th class="text-end">Action</th></tr></thead>
            <tbody>
                @forelse ($departments as $department)
                    <tr>
                        <td class="fw-medium">{{ $department->name }}</td>
                        <td><code>{{ $department->code }}</code></td>
                        <td>{{ $department->manager?->name ?? '—' }}</td>
                        <td><x-status-badge :status="$department->status" /></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('departments.edit', $department) }}">Edit</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty-state :title="$search ? 'No matching departments' : 'No departments yet'" :description="$search ? 'Try a different name or code.' : 'Add the first department to begin configuring your organization.'" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $departments->links() }}</div>
@endsection
