@extends('layouts.app')
@section('title', 'Expense claims')
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="h2 mb-1">Expense claims</h1><p class="text-secondary mb-0">Create and track employee expense claims.</p></div>
    @can('create', App\Models\ExpenseClaim::class)<a class="btn btn-primary" href="{{ route('expense-claims.create') }}">New claim</a>@endcan
</div>
<form class="row g-2 mb-4" method="GET">
    <div class="col-md-5"><label class="visually-hidden" for="search">Search</label><input class="form-control" id="search" name="search" value="{{ $search }}" placeholder="Search reference, title or employee"></div>
    <div class="col-md-3"><label class="visually-hidden" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">All statuses</option>@foreach (App\ExpenseClaimStatus::cases() as $option)<option value="{{ $option->value }}" @selected($status === $option->value)>{{ str($option->value)->replace('_', ' ')->title() }}</option>@endforeach</select></div>
    <div class="col-md-2"><label class="visually-hidden" for="category_id">Category</label><select class="form-select" id="category_id" name="category_id"><option value="">All categories</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name }}</option>@endforeach</select></div>
    <div class="col-auto"><button class="btn btn-outline-secondary" type="submit">Filter</button></div>
    @if ($search || $status || $categoryId)<div class="col-auto"><a class="btn btn-link" href="{{ route('expense-claims.index') }}">Clear</a></div>@endif
</form>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Reference</th><th>Claim</th><th>Department</th><th>Total</th><th>Status</th><th class="text-end">Action</th></tr></thead>
    <tbody>@forelse ($expenseClaims as $expenseClaim)
        <tr><td class="fw-semibold">{{ $expenseClaim->claim_no }}</td><td><div>{{ $expenseClaim->title }}</div><small class="text-secondary">{{ $expenseClaim->employee->name }}</small></td><td>{{ $expenseClaim->department->name }}</td><td>{{ App\Support\Money::of($expenseClaim->total_amount)->format($expenseClaim->currency) }}</td><td><span class="badge text-bg-{{ $expenseClaim->status === App\ExpenseClaimStatus::Draft ? 'secondary' : 'primary' }}">{{ str($expenseClaim->status->value)->replace('_', ' ')->title() }}</span></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('expense-claims.show', $expenseClaim) }}">View</a></td></tr>
    @empty<tr><td class="text-center text-secondary py-5" colspan="6">No expense claims found.</td></tr>@endforelse</tbody>
</table></div></div>
<div class="mt-3">{{ $expenseClaims->links() }}</div>
@endsection
