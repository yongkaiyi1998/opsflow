@extends('layouts.app')
@section('title', 'Purchase requests')
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="h2 mb-1">Purchase requests</h1><p class="text-secondary mb-0">Create and track requests for company spending.</p></div>
    <a class="btn btn-primary" href="{{ route('purchase-requests.create') }}">New request</a>
</div>
<form class="row g-2 mb-4" method="GET">
    <div class="col-md-5"><label class="visually-hidden" for="search">Search</label><input class="form-control" id="search" name="search" value="{{ $search }}" placeholder="Search reference, title, requester or vendor"></div>
    <div class="col-md-3"><label class="visually-hidden" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">All statuses</option>@foreach (App\PurchaseRequestStatus::cases() as $option)<option value="{{ $option->value }}" @selected($status === $option->value)>{{ str($option->value)->replace('_', ' ')->title() }}</option>@endforeach</select></div>
    <div class="col-auto"><button class="btn btn-outline-secondary" type="submit">Filter</button></div>
    @if ($search || $status)<div class="col-auto"><a class="btn btn-link" href="{{ route('purchase-requests.index') }}">Clear</a></div>@endif
</form>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Reference</th><th>Request</th><th>Department</th><th>Total</th><th>Status</th><th class="text-end">Action</th></tr></thead>
    <tbody>@forelse ($purchaseRequests as $purchaseRequest)
        <tr><td class="fw-semibold">{{ $purchaseRequest->request_no }}</td><td><div>{{ $purchaseRequest->title }}</div><small class="text-secondary">{{ $purchaseRequest->requester->name }} · {{ $purchaseRequest->category->name }}</small></td><td>{{ $purchaseRequest->department->name }}</td><td>{{ App\Support\Money::of($purchaseRequest->total_amount)->format($purchaseRequest->currency) }}</td><td><span class="badge text-bg-{{ $purchaseRequest->status === App\PurchaseRequestStatus::Draft ? 'secondary' : 'primary' }}">{{ str($purchaseRequest->status->value)->replace('_', ' ')->title() }}</span></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-requests.show', $purchaseRequest) }}">View</a></td></tr>
    @empty<tr><td class="text-center text-secondary py-5" colspan="6">No purchase requests found.</td></tr>@endforelse</tbody>
</table></div></div>
<div class="mt-3">{{ $purchaseRequests->links() }}</div>
@endsection
