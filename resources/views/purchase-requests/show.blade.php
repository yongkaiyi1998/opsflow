@extends('layouts.app')
@section('title', $purchaseRequest->request_no)
@section('content')
<x-page-header :title="$purchaseRequest->title" :description="$purchaseRequest->request_no">
    <x-slot:actions>
        @can('update', $purchaseRequest)<a class="btn btn-outline-secondary" href="{{ route('purchase-requests.edit', $purchaseRequest) }}">Edit request</a>@endcan
        @can('submit', $purchaseRequest)<form method="POST" action="{{ route('purchase-requests.submit', $purchaseRequest) }}">@csrf<button class="btn btn-primary" type="submit">Submit for approval</button></form>@endcan
        @can('withdraw', $purchaseRequest)<form method="POST" action="{{ route('purchase-requests.withdraw', $purchaseRequest) }}">@csrf<button class="btn btn-outline-danger" type="submit">Withdraw</button></form>@endcan
    </x-slot:actions>
</x-page-header>

@php($runtime = $purchaseRequest->approvalInstances->first())
@if ($purchaseRequest->status === App\PurchaseRequestStatus::ChangesRequested && $runtime)
    @php($changeRequest = $runtime->actions->where('action', App\ApprovalActionType::ChangesRequested)->last())
    <div class="alert alert-warning detail-callout" role="alert">
        <div class="detail-callout-copy"><h2>Changes requested</h2>@if ($changeRequest)<p>{{ $changeRequest->comment }}</p><small>Requested by {{ $changeRequest->actor?->name ?? 'an approver' }}@if ($changeRequest->step) at {{ $changeRequest->step->name }}@endif.</small>@endif</div>
        @can('resubmit', $purchaseRequest)<form class="detail-callout-form" method="POST" action="{{ route('purchase-requests.resubmit', $purchaseRequest) }}">@csrf<label class="form-label" for="resubmit-comment">Resubmission note <span class="text-secondary">(optional)</span></label><textarea class="form-control" id="resubmit-comment" name="comment" rows="2" maxlength="2000"></textarea><button class="btn btn-primary mt-2" type="submit">Resubmit for approval</button></form>@endcan
    </div>
@endif

<div class="detail-layout">
    <main class="detail-main">
        <section class="card detail-section"><div class="card-body">
            <div class="detail-section-heading"><div><h2>Request overview</h2><p>The business purpose and purchasing details for this request.</p></div></div>
            <div class="detail-description">{{ $purchaseRequest->description }}</div>
            <dl class="detail-metadata-grid mb-0"><div><dt>Vendor</dt><dd>{{ $purchaseRequest->vendor?->name ?? 'Not selected' }}</dd></div><div><dt>Needed by</dt><dd>{{ $purchaseRequest->needed_by_date?->format('j M Y') ?? 'Not specified' }}</dd></div></dl>
        </div></section>

        <section class="card detail-section"><div class="card-body p-0">
            <div class="detail-section-heading px-4 pt-4"><div><h2>Requested items</h2><p>{{ $purchaseRequest->items->count() }} {{ Str::plural('item', $purchaseRequest->items->count()) }} in this request.</p></div></div>
            <div class="table-responsive"><table class="table detail-table align-middle mb-0"><thead><tr><th>Description</th><th class="text-end">Qty</th><th class="text-end">Unit price</th><th class="text-end">Subtotal</th></tr></thead><tbody>
                @foreach ($purchaseRequest->items as $item)<tr><td class="fw-semibold text-body">{{ $item->description }}</td><td class="text-end">{{ $item->quantity }}</td><td class="text-end">{{ App\Support\Money::of($item->unit_price)->format($purchaseRequest->currency) }}</td><td class="text-end fw-semibold">{{ App\Support\Money::of($item->subtotal)->format($purchaseRequest->currency) }}</td></tr>@endforeach
            </tbody></table></div>
            <div class="money-summary"><dl><div><dt>Subtotal</dt><dd>{{ App\Support\Money::of($purchaseRequest->subtotal)->format($purchaseRequest->currency) }}</dd></div><div><dt>Tax</dt><dd>{{ App\Support\Money::of($purchaseRequest->tax_amount)->format($purchaseRequest->currency) }}</dd></div><div class="money-summary-total"><dt>Total</dt><dd>{{ App\Support\Money::of($purchaseRequest->total_amount)->format($purchaseRequest->currency) }}</dd></div></dl></div>
        </div></section>

        <section class="card detail-section"><div class="card-body">
            <div class="detail-section-heading"><div><h2>Attachments</h2><p>Documents are stored privately and checked before download.</p></div></div>
            <div class="attachment-list">
                @forelse ($purchaseRequest->attachments as $attachment)
                    <div class="attachment-item"><div class="attachment-icon" aria-hidden="true">DOC</div><div class="attachment-copy"><a href="{{ route('attachments.download', $attachment) }}">{{ $attachment->original_name }}</a><small>{{ number_format($attachment->size / 1024, 0) }} KB · Uploaded by {{ $attachment->uploadedBy?->name ?? 'Unknown' }}</small></div>@can('delete', $attachment)<form method="POST" action="{{ route('attachments.destroy', $attachment) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger text-decoration-none" type="submit">Delete</button></form>@endcan</div>
                @empty<x-empty-state title="No attachments" description="Supporting documents can be added while this request is editable." />@endforelse
            </div>
            @can('addAttachment', $purchaseRequest)<form class="attachment-upload" method="POST" enctype="multipart/form-data" action="{{ route('purchase-request-attachments.store', $purchaseRequest) }}">@csrf<div class="flex-grow-1"><label class="form-label" for="attachment">Add private attachment</label><input class="form-control" id="attachment" name="attachment" type="file" required></div><button class="btn btn-outline-primary" type="submit">Upload</button></form>@endcan
        </div></section>

        @foreach ($purchaseRequest->approvalInstances as $approvalInstance)<x-approval-timeline :instance="$approvalInstance" />@endforeach
    </main>

    <aside class="detail-sidebar">
        <div class="card detail-summary-card"><div class="card-body">
            <div class="detail-summary-status"><span>Request status</span><x-status-badge :status="$purchaseRequest->status" /></div>
            <div class="detail-total"><span>Total request</span><strong>{{ App\Support\Money::of($purchaseRequest->total_amount)->format($purchaseRequest->currency) }}</strong></div>
            <dl class="detail-summary-list"><div><dt>Requester</dt><dd>{{ $purchaseRequest->requester->name }}</dd></div><div><dt>Department</dt><dd>{{ $purchaseRequest->department->name }}</dd></div><div><dt>Category</dt><dd>{{ $purchaseRequest->category->name }}</dd></div><div><dt>Created</dt><dd>{{ $purchaseRequest->created_at->format('j M Y') }}</dd></div>@if ($purchaseRequest->submitted_at)<div><dt>Submitted</dt><dd>{{ $purchaseRequest->submitted_at->format('j M Y, H:i') }}</dd></div>@endif</dl>
        </div></div>
        @can('delete', $purchaseRequest)<div class="card detail-danger-card"><div class="card-body"><h2>Delete draft</h2><p>This removes the draft, its items, and attachments. The request number will not be reused.</p><form method="POST" action="{{ route('purchase-requests.destroy', $purchaseRequest) }}">@csrf @method('DELETE')<button class="btn btn-outline-danger w-100" type="submit">Delete draft</button></form></div></div>@endcan
    </aside>
</div>
@endsection
