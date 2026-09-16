@extends('layouts.app')
@section('title', 'Expense claims')
@section('content')
<x-page-header title="Expense claims" description="Create and track employee expense claims.">
    <x-slot:actions>@can('create', App\Models\ExpenseClaim::class)<a class="btn btn-primary" href="{{ route('expense-claims.create') }}">New claim</a>@endcan</x-slot:actions>
</x-page-header>

<form class="filter-panel" method="GET">
    <div class="row g-2 align-items-center">
        <div class="col-lg-6"><label class="visually-hidden" for="search">Search</label><input class="form-control" id="search" name="search" value="{{ $search }}" placeholder="Search reference, title or employee"></div>
        <div class="col-md-6 col-lg-2"><label class="visually-hidden" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">All statuses</option>@foreach (App\ExpenseClaimStatus::cases() as $option)<option value="{{ $option->value }}" @selected($status === $option->value)>{{ str($option->value)->replace('_', ' ')->title() }}</option>@endforeach</select></div>
        <div class="col-md-6 col-lg-2"><label class="visually-hidden" for="category_id">Category</label><select class="form-select" id="category_id" name="category_id"><option value="">All categories</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name }}</option>@endforeach</select></div>
        <div class="col-md-auto"><button class="btn btn-outline-secondary" type="submit">Apply filters</button></div>
        @if ($search || $status || $categoryId)<div class="col-md-auto"><a class="btn btn-link" href="{{ route('expense-claims.index') }}">Clear</a></div>@endif
    </div>
</form>

<div class="card table-card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Reference</th><th>Claim</th><th>Department</th><th>Total</th><th>Status</th><th class="text-end">Action</th></tr></thead>
    <tbody>
    @forelse ($expenseClaims as $expenseClaim)
        <tr><td class="fw-semibold text-nowrap">{{ $expenseClaim->claim_no }}</td><td><div class="fw-medium">{{ $expenseClaim->title }}</div><small class="text-secondary">{{ $expenseClaim->employee->name }}</small></td><td>{{ $expenseClaim->department->name }}</td><td class="text-nowrap fw-medium">{{ App\Support\Money::of($expenseClaim->total_amount)->format($expenseClaim->currency) }}</td><td><x-status-badge :status="$expenseClaim->status" /></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('expense-claims.show', $expenseClaim) }}">View</a></td></tr>
    @empty
        <tr><td colspan="6"><x-empty-state :title="$search || $status || $categoryId ? 'No matching claims' : 'No expense claims yet'" :description="$search || $status || $categoryId ? 'Try adjusting or clearing your filters.' : 'Create a claim when you have reimbursable expenses to submit.'" /></td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $expenseClaims->links() }}</div>
@endsection
