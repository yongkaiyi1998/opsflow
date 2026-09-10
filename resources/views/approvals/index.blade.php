@extends('layouts.app')
@section('title', 'Approval inbox')
@section('content')
<div class="mb-4"><h1 class="h2 mb-1">Approval inbox</h1><p class="text-secondary mb-0">Requests currently assigned to you.</p></div>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Reference</th><th>Module</th><th>Requester</th><th>Amount</th><th>Current step</th><th>Assigned</th><th class="text-end">Action</th></tr></thead>
    <tbody>@forelse ($assignments as $assignment)
        @php
            $instance = $assignment->step->approvalInstance;
            $business = $instance->approvable;
            $context = $instance->context();
            $reference = match (true) {
                $business instanceof App\Models\PurchaseRequest => $business->request_no,
                $business instanceof App\Models\SupplierInvoice => $business->internal_no,
                $business instanceof App\Models\ExpenseClaim => $business->claim_no,
                default => 'Unavailable',
            };
            $requester = match (true) {
                $business instanceof App\Models\PurchaseRequest => $business->requester?->name,
                $business instanceof App\Models\SupplierInvoice => $business->submittedBy?->name,
                $business instanceof App\Models\ExpenseClaim => $business->employee?->name,
                default => null,
            };
        @endphp
        <tr><td class="fw-semibold">{{ $reference }}</td><td>{{ $context->moduleType->label() }}</td><td>{{ $requester ?? 'Unavailable' }}</td><td>{{ $context->amount->format($context->currency) }}</td><td>{{ $assignment->step->name }}</td><td>{{ $assignment->assigned_at->format('j M Y H:i') }}</td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('approvals.show', $assignment) }}">Review</a></td></tr>
    @empty<tr><td class="text-center text-secondary py-5" colspan="7">You have no pending approvals.</td></tr>@endforelse</tbody>
</table></div></div>
<div class="mt-3">{{ $assignments->links() }}</div>
@endsection
