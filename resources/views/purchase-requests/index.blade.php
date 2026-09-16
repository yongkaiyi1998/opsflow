@extends('layouts.app')
@section('title', 'Purchase requests')
@section('content')
<x-page-header title="Purchase requests" description="Create, submit and track requests for company spending.">
    <x-slot:actions>
        @can('create', App\Models\PurchaseRequest::class)
            <a class="btn btn-primary" href="{{ route('purchase-requests.create') }}">New request</a>
        @endcan
    </x-slot:actions>
</x-page-header>

<form class="filter-panel" method="GET">
    <div class="row g-2 align-items-center">
        <div class="col-lg-6"><label class="visually-hidden" for="search">Search</label><input class="form-control" id="search" name="search" value="{{ $search }}" placeholder="Search reference, title, requester or vendor"></div>
        <div class="col-md-5 col-lg-3"><label class="visually-hidden" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">All statuses</option>@foreach (App\PurchaseRequestStatus::cases() as $option)<option value="{{ $option->value }}" @selected($status === $option->value)>{{ str($option->value)->replace('_', ' ')->title() }}</option>@endforeach</select></div>
        <div class="col-md-auto"><button class="btn btn-outline-secondary" type="submit">Apply filters</button></div>
        @if ($search || $status)<div class="col-md-auto"><a class="btn btn-link" href="{{ route('purchase-requests.index') }}">Clear</a></div>@endif
    </div>
</form>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Reference</th><th>Request</th><th>Department</th><th>Total</th><th>Status</th><th class="text-end">Action</th></tr></thead>
            <tbody>
                @forelse ($purchaseRequests as $purchaseRequest)
                    <tr>
                        <td class="fw-semibold text-nowrap">{{ $purchaseRequest->request_no }}</td>
                        <td><div class="fw-medium">{{ $purchaseRequest->title }}</div><small class="text-secondary">{{ $purchaseRequest->requester->name }} · {{ $purchaseRequest->category->name }}</small></td>
                        <td>{{ $purchaseRequest->department->name }}</td>
                        <td class="text-nowrap fw-medium">{{ App\Support\Money::of($purchaseRequest->total_amount)->format($purchaseRequest->currency) }}</td>
                        <td><x-status-badge :status="$purchaseRequest->status" /></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-requests.show', $purchaseRequest) }}">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty-state :title="$search || $status ? 'No matching requests' : 'No purchase requests yet'" :description="$search || $status ? 'Try adjusting or clearing your filters.' : 'Create a request when you need approval for company spending.'">
                        @if (! $search && ! $status)
                            <x-slot:action><a class="btn btn-sm btn-primary" href="{{ route('purchase-requests.create') }}">Create first request</a></x-slot:action>
                        @endif
                    </x-empty-state></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $purchaseRequests->links() }}</div>
@endsection
