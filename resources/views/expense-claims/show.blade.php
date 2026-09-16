@extends('layouts.app')
@section('title', $expenseClaim->claim_no)
@section('content')
@php
    $runtime = $expenseClaim->approvalInstances->first();
    $routingItem = $expenseClaim->items->reduce(function ($highest, $item) {
        return $highest === null || App\Support\Money::of($item->amount)->isGreaterThan($highest->amount) ? $item : $highest;
    });
@endphp

<x-page-header :title="$expenseClaim->title" :description="$expenseClaim->claim_no">
    <x-slot:actions>
        @can('update', $expenseClaim)<a class="btn btn-outline-secondary" href="{{ route('expense-claims.edit', $expenseClaim) }}">Edit claim</a>@endcan
        @can('submit', $expenseClaim)<form method="POST" action="{{ route('expense-claims.submit', $expenseClaim) }}">@csrf<button class="btn btn-primary" type="submit">Submit for approval</button></form>@endcan
        @can('withdraw', $expenseClaim)<form method="POST" action="{{ route('expense-claims.withdraw', $expenseClaim) }}">@csrf<button class="btn btn-outline-danger" type="submit">Withdraw</button></form>@endcan
    </x-slot:actions>
</x-page-header>

@if ($expenseClaim->status === App\ExpenseClaimStatus::ChangesRequested && $runtime)
    @php($changeRequest = $runtime->actions->where('action', App\ApprovalActionType::ChangesRequested)->last())
    <div class="alert alert-warning detail-callout" role="alert">
        <div class="detail-callout-copy"><h2>Changes requested</h2>@if ($changeRequest)<p>{{ $changeRequest->comment }}</p><small>Requested by {{ $changeRequest->actor?->name ?? 'an approver' }}@if ($changeRequest->step) at {{ $changeRequest->step->name }}@endif.</small>@endif</div>
        @can('resubmit', $expenseClaim)<form class="detail-callout-form" method="POST" action="{{ route('expense-claims.resubmit', $expenseClaim) }}">@csrf<label class="form-label" for="resubmit-comment">Resubmission note <span class="text-secondary">(optional)</span></label><textarea class="form-control" id="resubmit-comment" name="comment" rows="2" maxlength="2000"></textarea><button class="btn btn-primary mt-2" type="submit">Resubmit for approval</button></form>@endcan
    </div>
@endif

<div class="detail-layout">
    <main class="detail-main">
        <section class="card detail-section"><div class="card-body">
            <div class="detail-section-heading"><div><h2>Claim overview</h2><p>The purpose and context for these employee expenses.</p></div></div>
            <div class="detail-description">{{ $expenseClaim->description }}</div>
            <dl class="detail-metadata-grid mb-0"><div><dt>Employee</dt><dd>{{ $expenseClaim->employee->name }}</dd></div><div><dt>Department</dt><dd>{{ $expenseClaim->department->name }}</dd></div></dl>
        </div></section>

        <section class="card detail-section"><div class="card-body">
            <div class="detail-section-heading"><div><h2>Expense items</h2><p>{{ $expenseClaim->items->count() }} {{ Str::plural('expense', $expenseClaim->items->count()) }} with item-level receipt tracking.</p></div></div>
            <div class="expense-item-list">
                @foreach ($expenseClaim->items as $item)
                    <article class="expense-detail-item">
                        <div class="expense-detail-header"><div><span>{{ $item->expense_date->format('j M Y') }} · {{ $item->category->name }}</span><h3>{{ $item->description }}</h3></div><strong>{{ App\Support\Money::of($item->amount)->format($expenseClaim->currency) }}</strong></div>
                        <dl class="detail-metadata-grid expense-metadata"><div><dt>Merchant</dt><dd>{{ $item->merchant ?: 'Not specified' }}</dd></div><div><dt>Tax included</dt><dd>{{ App\Support\Money::of($item->tax_amount)->format($expenseClaim->currency) }} <small>(informational)</small></dd></div></dl>
                        <div class="receipt-block">
                            <div class="receipt-block-header"><h4>Private receipt</h4>@if ($item->attachments->isNotEmpty())<span class="receipt-state receipt-state-complete">Attached</span>@elseif ($item->receipt_required)<span class="receipt-state receipt-state-required">Required</span>@else<span class="receipt-state">Exempt</span>@endif</div>
                            @forelse ($item->attachments as $attachment)
                                <div class="attachment-item"><div class="attachment-icon" aria-hidden="true">RCT</div><div class="attachment-copy"><a href="{{ route('attachments.download', $attachment) }}">{{ $attachment->original_name }}</a><small>{{ number_format($attachment->size / 1024, 0) }} KB · Uploaded by {{ $attachment->uploadedBy?->name ?? 'Unknown' }}</small></div>@can('delete', $attachment)<form method="POST" action="{{ route('attachments.destroy', $attachment) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger text-decoration-none" type="submit">Delete</button></form>@endcan</div>
                            @empty
                                @if ($item->receipt_required)<p class="text-danger small mb-2">A receipt is required before submission.</p>@else<p class="text-secondary small mb-2">This item is exempt from the receipt requirement.</p>@endif
                            @endforelse
                            @can('addAttachment', $item)<form class="attachment-upload attachment-upload-compact" method="POST" enctype="multipart/form-data" action="{{ route('expense-item-attachments.store', $item) }}">@csrf<div class="flex-grow-1"><label class="visually-hidden" for="attachment-{{ $item->id }}">Receipt</label><input class="form-control form-control-sm" id="attachment-{{ $item->id }}" name="attachment" type="file" required></div><button class="btn btn-sm btn-outline-primary" type="submit">Upload receipt</button></form>@endcan
                        </div>
                    </article>
                @endforeach
            </div>
        </div></section>

        @foreach ($expenseClaim->approvalInstances as $approvalInstance)<x-approval-timeline :instance="$approvalInstance" />@endforeach
    </main>

    <aside class="detail-sidebar">
        <div class="card detail-summary-card"><div class="card-body">
            <div class="detail-summary-status"><span>Claim status</span><x-status-badge :status="$expenseClaim->status" /></div>
            <div class="detail-total"><span>Claim total</span><strong>{{ App\Support\Money::of($expenseClaim->total_amount)->format($expenseClaim->currency) }}</strong><small>Sum of gross item amounts</small></div>
            <dl class="detail-summary-list"><div><dt>Employee</dt><dd>{{ $expenseClaim->employee->name }}</dd></div><div><dt>Department</dt><dd>{{ $expenseClaim->department->name }}</dd></div><div><dt>Routing category</dt><dd>{{ $routingItem?->category->name ?? 'Not available' }}</dd></div><div><dt>Created</dt><dd>{{ $expenseClaim->created_at->format('j M Y') }}</dd></div>@if ($expenseClaim->submitted_at)<div><dt>Submitted</dt><dd>{{ $expenseClaim->submitted_at->format('j M Y, H:i') }}</dd></div>@endif</dl>
        </div></div>
        @can('delete', $expenseClaim)<div class="card detail-danger-card"><div class="card-body"><h2>Delete draft</h2><p>This removes the draft, its items, and receipts. The claim reference will not be reused.</p><form method="POST" action="{{ route('expense-claims.destroy', $expenseClaim) }}">@csrf @method('DELETE')<button class="btn btn-outline-danger w-100" type="submit">Delete draft</button></form></div></div>@endcan
    </aside>
</div>
@endsection
