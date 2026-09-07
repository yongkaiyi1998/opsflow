@extends('layouts.app')
@section('title', 'Spend categories')
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h1 class="h2 mb-1">Spend categories</h1><p class="text-secondary mb-0">Maintain financial classification options.</p></div><a class="btn btn-primary" href="{{ route('spend-categories.create') }}">Add category</a></div>
<form class="row g-2 mb-4" method="GET"><div class="col-md-5"><label class="visually-hidden" for="search">Search categories</label><input class="form-control" id="search" name="search" value="{{ $search }}" placeholder="Search name or code"></div><div class="col-auto"><button class="btn btn-outline-secondary" type="submit">Search</button></div>@if ($search)<div class="col-auto"><a class="btn btn-link" href="{{ route('spend-categories.index') }}">Clear</a></div>@endif</form>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Name</th><th>Code</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody>
@forelse ($spendCategories as $spendCategory)<tr><td>{{ $spendCategory->name }}</td><td><code>{{ $spendCategory->code }}</code></td><td><span class="badge {{ $spendCategory->status === App\MasterDataStatus::Active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ ucfirst(strtolower($spendCategory->status->value)) }}</span></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('spend-categories.edit', $spendCategory) }}">Edit</a></td></tr>@empty<tr><td class="text-center text-secondary py-5" colspan="4">No spend categories found.</td></tr>@endforelse
</tbody></table></div></div><div class="mt-3">{{ $spendCategories->links() }}</div>
@endsection
