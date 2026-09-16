@extends('layouts.app')
@section('title', 'Spend categories')
@section('content')
<x-page-header title="Spend categories" description="Maintain the financial classifications used for routing and reporting.">
    <x-slot:actions><a class="btn btn-primary" href="{{ route('spend-categories.create') }}">Add category</a></x-slot:actions>
</x-page-header>
<form class="filter-panel" method="GET"><div class="row g-2 align-items-center"><div class="col-lg-6"><label class="visually-hidden" for="search">Search categories</label><input class="form-control" id="search" name="search" value="{{ $search }}" placeholder="Search name or code"></div><div class="col-md-auto"><button class="btn btn-outline-secondary" type="submit">Search</button></div>@if ($search)<div class="col-md-auto"><a class="btn btn-link" href="{{ route('spend-categories.index') }}">Clear</a></div>@endif</div></form>
<div class="card table-card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Name</th><th>Code</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody>
@forelse ($spendCategories as $spendCategory)<tr><td class="fw-medium">{{ $spendCategory->name }}</td><td><code>{{ $spendCategory->code }}</code></td><td><x-status-badge :status="$spendCategory->status" /></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('spend-categories.edit', $spendCategory) }}">Edit</a></td></tr>@empty<tr><td colspan="4"><x-empty-state :title="$search ? 'No matching categories' : 'No spend categories yet'" :description="$search ? 'Try a different name or code.' : 'Add the first category to support spend classification.'" /></td></tr>@endforelse
</tbody></table></div></div><div class="mt-3">{{ $spendCategories->links() }}</div>
@endsection
