@extends('layouts.app')
@section('title', 'Vendors')
@section('content')
<x-page-header title="Vendors" description="Maintain supplier contact and lifecycle details.">
    <x-slot:actions><a class="btn btn-primary" href="{{ route('vendors.create') }}">Add vendor</a></x-slot:actions>
</x-page-header>
<form class="filter-panel" method="GET"><div class="row g-2 align-items-center"><div class="col-lg-6"><label class="visually-hidden" for="search">Search vendors</label><input class="form-control" id="search" name="search" value="{{ $search }}" placeholder="Search name, code, email or phone"></div><div class="col-md-auto"><button class="btn btn-outline-secondary" type="submit">Search</button></div>@if ($search)<div class="col-md-auto"><a class="btn btn-link" href="{{ route('vendors.index') }}">Clear</a></div>@endif</div></form>
<div class="card table-card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Vendor</th><th>Code</th><th>Contact</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody>
@forelse ($vendors as $vendor)<tr><td class="fw-medium">{{ $vendor->name }}</td><td>{{ $vendor->code ?? '—' }}</td><td><div>{{ $vendor->email ?? '—' }}</div>@if ($vendor->phone)<small class="text-secondary">{{ $vendor->phone }}</small>@endif</td><td><x-status-badge :status="$vendor->status" /></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('vendors.edit', $vendor) }}">Edit</a></td></tr>@empty<tr><td colspan="5"><x-empty-state :title="$search ? 'No matching vendors' : 'No vendors yet'" :description="$search ? 'Try a different name, code or contact detail.' : 'Add the first vendor to begin recording supplier transactions.'" /></td></tr>@endforelse
</tbody></table></div></div><div class="mt-3">{{ $vendors->links() }}</div>
@endsection
