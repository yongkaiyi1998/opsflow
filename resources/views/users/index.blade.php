@extends('layouts.app')
@section('title', 'Users')
@section('content')
<x-page-header title="Users" description="Maintain access, roles and organization assignments.">
    <x-slot:actions><a class="btn btn-primary" href="{{ route('users.create') }}">Add user</a></x-slot:actions>
</x-page-header>
<form class="filter-panel" method="GET"><div class="row g-2 align-items-center"><div class="col-lg-6"><label class="visually-hidden" for="search">Search users</label><input class="form-control" id="search" name="search" value="{{ $search }}" placeholder="Search name, email or department"></div><div class="col-md-auto"><button class="btn btn-outline-secondary" type="submit">Search</button></div>@if ($search)<div class="col-md-auto"><a class="btn btn-link" href="{{ route('users.index') }}">Clear</a></div>@endif</div></form>
<div class="card table-card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>User</th><th>Role</th><th>Department</th><th>Manager</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody>
@forelse ($users as $user)<tr><td><div class="fw-semibold">{{ $user->name }}</div><small class="text-secondary">{{ $user->email }}</small></td><td>{{ ucfirst(strtolower($user->role->value)) }}</td><td>{{ $user->department?->name ?? '—' }}@if ($user->department && $user->department->status === App\MasterDataStatus::Inactive)<small class="text-secondary d-block">Inactive department</small>@endif</td><td>{{ $user->manager?->name ?? '—' }}</td><td><x-status-badge :status="$user->status" /></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('users.edit', $user) }}">Edit</a></td></tr>@empty<tr><td colspan="6"><x-empty-state :title="$search ? 'No matching users' : 'No users yet'" :description="$search ? 'Try a different name, email or department.' : 'Add the first user to begin assigning access.'" /></td></tr>@endforelse
</tbody></table></div></div><div class="mt-3">{{ $users->links() }}</div>
@endsection
