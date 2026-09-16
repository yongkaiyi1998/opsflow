@extends('layouts.app')
@section('title', 'Approval inbox')
@section('content')
<x-page-header title="Approval inbox" description="Review requests waiting for your decision. Only current, actionable assignments appear here." />

<div class="approval-inbox-intro">
    <div><span class="approval-inbox-count">{{ $assignments->total() }}</span><span>Pending {{ Str::plural('decision', $assignments->total()) }}</span></div>
    <p>Assignments are ordered by when they reached you, with the newest first.</p>
</div>

<div class="card table-card approval-inbox-table">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Document</th><th>Submitted by</th><th>Amount</th><th>Approval context</th><th>Waiting since</th><th class="text-end"><span class="visually-hidden">Action</span></th></tr></thead>
            <tbody>
                @forelse ($assignments as $assignment)
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
                    <tr>
                        <td><span class="approval-document-type">{{ $context->moduleType->label() }}</span><strong class="approval-document-reference">{{ $reference }}</strong></td>
                        <td>{{ $requester ?? 'Unavailable' }}</td>
                        <td class="text-nowrap"><strong class="approval-amount">{{ $context->amount->format($context->currency) }}</strong></td>
                        <td><span class="approval-assigned-label">Assigned to you</span><span class="approval-step-name">{{ $assignment->step->name }}</span></td>
                        <td class="text-nowrap"><span>{{ $assignment->assigned_at->format('j M Y') }}</span><small class="text-secondary d-block">{{ $assignment->assigned_at->diffForHumans() }}</small></td>
                        <td class="text-end"><a class="btn btn-sm btn-primary" href="{{ route('approvals.show', $assignment) }}">Review</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty-state title="Your approval inbox is clear" description="New assignments will appear here when a workflow reaches your step." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $assignments->links() }}</div>
@endsection
