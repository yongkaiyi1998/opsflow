@extends('layouts.app')
@section('title', 'Departments')
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="h2 mb-1">Departments</h1><p class="text-secondary mb-0">Maintain organization units and their managers.</p></div>
    <a class="btn btn-primary" href="{{ route('departments.create') }}">Add department</a>
</div>
<form class="row g-2 mb-4" method="GET" action="{{ route('departments.index') }}">
    <div class="col-md-5"><label class="visually-hidden" for="search">Search departments</label><input class="form-control" id="search" name="search" value="{{ $search }}" placeholder="Search name or code"></div>
    <div class="col-auto"><button class="btn btn-outline-secondary" type="submit">Search</button></div>
    @if ($search)<div class="col-auto"><a class="btn btn-link" href="{{ route('departments.index') }}">Clear</a></div>@endif
</form>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Name</th><th>Code</th><th>Manager</th><th>Status</th><th class="text-end">Action</th></tr></thead>
    <tbody>
    @forelse ($departments as $department)
        <tr><td>{{ $department->name }}</td><td><code>{{ $department->code }}</code></td><td>{{ $department->manager?->name ?? '—' }}</td><td><span class="badge {{ $department->status === App\MasterDataStatus::Active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ ucfirst(strtolower($department->status->value)) }}</span></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('departments.edit', $department) }}">Edit</a></td></tr>
    @empty
        <tr><td class="text-center text-secondary py-5" colspan="5">No departments found.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $departments->links() }}</div>
@endsection
