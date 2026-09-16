@extends('layouts.app')
@section('title', 'Approval review')
@section('content')
@php
    $context = $instance->context();
    $reference = match (true) {
        $business instanceof App\Models\PurchaseRequest => $business->request_no,
        $business instanceof App\Models\SupplierInvoice => $business->internal_no,
        $business instanceof App\Models\ExpenseClaim => $business->claim_no,
    };
    $documentTitle = match (true) {
        $business instanceof App\Models\PurchaseRequest => $business->title,
        $business instanceof App\Models\SupplierInvoice => 'Invoice '.$business->invoice_no,
        $business instanceof App\Models\ExpenseClaim => $business->title,
    };
    $requester = match (true) {
        $business instanceof App\Models\PurchaseRequest => $business->requester,
        $business instanceof App\Models\SupplierInvoice => $business->submittedBy,
        $business instanceof App\Models\ExpenseClaim => $business->employee,
    };
    $routingItem = $business instanceof App\Models\ExpenseClaim
        ? $business->items->reduce(function ($highest, $item) {
            return $highest === null || App\Support\Money::of($item->amount)->isGreaterThan($highest->amount) ? $item : $highest;
        })
        : null;
    $categoryName = match (true) {
        $business instanceof App\Models\PurchaseRequest => $business->category->name,
        $business instanceof App\Models\SupplierInvoice => $business->category->name,
        $business instanceof App\Models\ExpenseClaim => $routingItem?->category->name ?? 'Not available',
    };
    $businessRoute = match (true) {
        $business instanceof App\Models\PurchaseRequest => route('purchase-requests.show', $business),
        $business instanceof App\Models\SupplierInvoice => route('supplier-invoices.show', $business),
        $business instanceof App\Models\ExpenseClaim => route('expense-claims.show', $business),
    };
@endphp

<x-page-header :title="$documentTitle" :description="$context->moduleType->label().' · '.$reference">
    <x-slot:actions><a class="btn btn-outline-secondary" href="{{ $businessRoute }}">Open business record</a></x-slot:actions>
</x-page-header>

<div class="approval-attention-banner">
    <span class="approval-attention-icon" aria-hidden="true">!</span>
    <div><strong>Assigned to you for a decision</strong><p>This request is waiting at the {{ $approvalAssignment->step->name }} step.</p></div>
</div>

<div class="detail-layout approval-review-layout">
    <main class="detail-main">
        <section class="card detail-section"><div class="card-body">
            <div class="detail-section-heading"><div><h2>What you are reviewing</h2><p>Business purpose and key document information.</p></div></div>
            <div class="detail-description">{{ $business->description }}</div>
            <dl class="detail-metadata-grid mb-0">
                <div><dt>Reference</dt><dd>{{ $reference }}</dd></div>
                <div><dt>Document type</dt><dd>{{ $context->moduleType->label() }}</dd></div>
                @if ($business instanceof App\Models\PurchaseRequest)
                    <div><dt>Vendor</dt><dd>{{ $business->vendor?->name ?? 'Not selected' }}</dd></div><div><dt>Needed by</dt><dd>{{ $business->needed_by_date?->format('j M Y') ?? 'Not specified' }}</dd></div>
                @elseif ($business instanceof App\Models\SupplierInvoice)
                    <div><dt>Supplier invoice no.</dt><dd>{{ $business->invoice_no }}</dd></div><div><dt>Invoice date</dt><dd>{{ $business->invoice_date->format('j M Y') }}</dd></div><div><dt>Due date</dt><dd>{{ $business->due_date?->format('j M Y') ?? 'Not specified' }}</dd></div><div><dt>Vendor</dt><dd>{{ $business->vendor->name }}</dd></div>
                @else
                    <div><dt>Expense count</dt><dd>{{ $business->items->count() }} {{ Str::plural('item', $business->items->count()) }}</dd></div><div><dt>Routing category</dt><dd>{{ $categoryName }}</dd></div>
                @endif
            </dl>
        </div></section>

        @if ($business instanceof App\Models\ExpenseClaim)
            <section class="card detail-section"><div class="card-body">
                <div class="detail-section-heading"><div><h2>Expense items</h2><p>Gross expense amounts with informational tax and receipt status.</p></div></div>
                <div class="expense-item-list">
                    @foreach ($business->items as $item)
                        <article class="expense-detail-item">
                            <div class="expense-detail-header"><div><span>{{ $item->expense_date->format('j M Y') }} · {{ $item->category->name }}</span><h3>{{ $item->description }}</h3></div><strong>{{ App\Support\Money::of($item->amount)->format($business->currency) }}</strong></div>
                            <dl class="detail-metadata-grid expense-metadata"><div><dt>Merchant</dt><dd>{{ $item->merchant ?: 'Not specified' }}</dd></div><div><dt>Tax included</dt><dd>{{ App\Support\Money::of($item->tax_amount)->format($business->currency) }} <small>(informational)</small></dd></div></dl>
                            <div class="receipt-block"><div class="receipt-block-header"><h4>Private receipt</h4>@if ($item->attachments->isNotEmpty())<span class="receipt-state receipt-state-complete">Attached</span>@elseif ($item->receipt_required)<span class="receipt-state receipt-state-required">Required</span>@else<span class="receipt-state">Exempt</span>@endif</div>
                                @forelse ($item->attachments as $attachment)<div class="attachment-item"><div class="attachment-icon" aria-hidden="true">RCT</div><div class="attachment-copy"><a href="{{ route('attachments.download', $attachment) }}">{{ $attachment->original_name }}</a><small>{{ number_format($attachment->size / 1024, 0) }} KB · Private document</small></div></div>@empty<p class="text-secondary small mb-0">No receipt attached.</p>@endforelse
                            </div>
                        </article>
                    @endforeach
                </div>
            </div></section>
        @else
            <section class="card detail-section"><div class="card-body p-0">
                <div class="detail-section-heading px-4 pt-4"><div><h2>{{ $business instanceof App\Models\PurchaseRequest ? 'Requested items' : 'Invoice line items' }}</h2><p>{{ $business->items->count() }} {{ Str::plural('line', $business->items->count()) }} included in this document.</p></div></div>
                <div class="table-responsive"><table class="table detail-table align-middle mb-0"><thead><tr><th>Description</th><th class="text-end">Quantity</th><th class="text-end">Unit price</th><th class="text-end">Subtotal</th></tr></thead><tbody>
                    @foreach ($business->items as $item)<tr><td class="fw-semibold text-body">{{ $item->description }}</td><td class="text-end">{{ $business instanceof App\Models\PurchaseRequest ? $item->quantity : rtrim(rtrim($item->quantity, '0'), '.') }}</td><td class="text-end">{{ App\Support\Money::of($item->unit_price)->format($business->currency) }}</td><td class="text-end fw-semibold">{{ App\Support\Money::of($item->subtotal)->format($business->currency) }}</td></tr>@endforeach
                </tbody></table></div>
                <div class="money-summary"><dl><div><dt>Subtotal</dt><dd>{{ App\Support\Money::of($business->subtotal)->format($business->currency) }}</dd></div><div><dt>Tax</dt><dd>{{ App\Support\Money::of($business->tax_amount)->format($business->currency) }}</dd></div><div class="money-summary-total"><dt>Total</dt><dd>{{ App\Support\Money::of($business->total_amount)->format($business->currency) }}</dd></div></dl></div>
            </div></section>

            <section class="card detail-section"><div class="card-body">
                <div class="detail-section-heading"><div><h2>Private attachments</h2><p>Supporting documents remain protected by document authorization.</p></div></div>
                <div class="attachment-list">
                    @forelse ($business->attachments as $attachment)<div class="attachment-item"><div class="attachment-icon" aria-hidden="true">DOC</div><div class="attachment-copy"><a href="{{ route('attachments.download', $attachment) }}">{{ $attachment->original_name }}</a><small>{{ number_format($attachment->size / 1024, 0) }} KB · Private document</small></div></div>@empty<x-empty-state title="No attachments" description="No supporting documents are attached to this record." />@endforelse
                </div>
            </div></section>
        @endif

        <x-approval-timeline :instance="$instance" />
    </main>

    <aside class="detail-sidebar">
        <div class="card approval-decision-card"><div class="card-body">
            <div class="detail-summary-status"><span>Runtime status</span><x-status-badge :status="$instance->status" /></div>
            <div class="detail-total"><span>Total under review</span><strong>{{ $context->amount->format($context->currency) }}</strong></div>
            <div class="approval-assignment-context"><span>YOUR APPROVAL STEP</span><strong>{{ $approvalAssignment->step->name }}</strong><small>Assigned {{ $approvalAssignment->assigned_at->diffForHumans() }}</small></div>
            <dl class="detail-summary-list">
                <div><dt>{{ $business instanceof App\Models\ExpenseClaim ? 'Employee' : ($business instanceof App\Models\SupplierInvoice ? 'Submitted by' : 'Requester') }}</dt><dd>{{ $requester->name }}</dd></div>
                <div><dt>Department</dt><dd>{{ $business->department->name }}</dd></div>
                <div><dt>Category</dt><dd>{{ $categoryName }}</dd></div>
                @if ($business instanceof App\Models\PurchaseRequest)<div><dt>Vendor</dt><dd>{{ $business->vendor?->name ?? 'Not selected' }}</dd></div>@elseif ($business instanceof App\Models\SupplierInvoice)<div><dt>Vendor</dt><dd>{{ $business->vendor->name }}</dd></div>@endif
            </dl>

            @if ($actionable)
                <div class="approval-actions" aria-labelledby="approval-actions-heading">
                    <div class="approval-actions-heading"><h2 id="approval-actions-heading">Make a decision</h2><p>Your first committed action is final for this assignment.</p></div>
                    <form class="approval-action approval-action-primary" method="POST" action="{{ route('approval-assignments.approve', $approvalAssignment) }}">@csrf<label class="form-label" for="approve-comment">Approval comment <span class="text-secondary">(optional)</span></label><textarea class="form-control" id="approve-comment" name="comment" rows="2" maxlength="2000"></textarea><button class="btn btn-success w-100" type="submit">Approve</button></form>
                    <form class="approval-action approval-action-caution" method="POST" action="{{ route('approval-assignments.request-changes', $approvalAssignment) }}">@csrf<label class="form-label" for="changes-comment">Required changes</label><small>Explain what the requester needs to update.</small><textarea class="form-control" id="changes-comment" name="comment" rows="3" maxlength="2000" required></textarea><button class="btn btn-warning w-100" type="submit">Request changes</button></form>
                    <form class="approval-action approval-action-danger" method="POST" action="{{ route('approval-assignments.reject', $approvalAssignment) }}">@csrf<label class="form-label" for="reject-comment">Rejection reason</label><small>Rejecting ends this approval process.</small><textarea class="form-control" id="reject-comment" name="comment" rows="3" maxlength="2000" required></textarea><button class="btn btn-outline-danger w-100" type="submit">Reject</button></form>
                </div>
            @else
                <div class="approval-unavailable"><strong>No action available</strong><p>This assignment is no longer actionable. Its history remains visible for reference.</p></div>
            @endif
        </div></div>
    </aside>
</div>
@endsection
