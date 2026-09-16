@extends('layouts.app')
@section('title', $supplierInvoice->internal_no)
@section('content')
<x-page-header :title="'Invoice '.$supplierInvoice->invoice_no" :description="$supplierInvoice->internal_no">
    <x-slot:actions>
        @can('update', $supplierInvoice)<a class="btn btn-outline-secondary" href="{{ route('supplier-invoices.edit', $supplierInvoice) }}">Edit invoice</a>@endcan
        @can('submit', $supplierInvoice)<form method="POST" action="{{ route('supplier-invoices.submit', $supplierInvoice) }}">@csrf<button class="btn btn-primary" type="submit">Submit for approval</button></form>@endcan
        @can('withdraw', $supplierInvoice)<form method="POST" action="{{ route('supplier-invoices.withdraw', $supplierInvoice) }}">@csrf<button class="btn btn-outline-danger" type="submit">Withdraw</button></form>@endcan
    </x-slot:actions>
</x-page-header>

@php($runtime = $supplierInvoice->approvalInstances->first())
@if ($supplierInvoice->status === App\SupplierInvoiceStatus::ChangesRequested && $runtime)
    @php($changeRequest = $runtime->actions->where('action', App\ApprovalActionType::ChangesRequested)->last())
    <div class="alert alert-warning detail-callout" role="alert">
        <div class="detail-callout-copy"><h2>Changes requested</h2>@if ($changeRequest)<p>{{ $changeRequest->comment }}</p><small>Requested by {{ $changeRequest->actor?->name ?? 'an approver' }}@if ($changeRequest->step) at {{ $changeRequest->step->name }}@endif.</small>@endif</div>
        @can('resubmit', $supplierInvoice)<form class="detail-callout-form" method="POST" action="{{ route('supplier-invoices.resubmit', $supplierInvoice) }}">@csrf<label class="form-label" for="resubmit-comment">Resubmission note <span class="text-secondary">(optional)</span></label><textarea class="form-control" id="resubmit-comment" name="comment" rows="2" maxlength="2000"></textarea><button class="btn btn-primary mt-2" type="submit">Resubmit for approval</button></form>@endcan
    </div>
@endif

<div class="detail-layout">
    <main class="detail-main">
        <section class="card detail-section"><div class="card-body">
            <div class="detail-section-heading"><div><h2>Invoice overview</h2><p>Supplier, payment timing, and invoice context.</p></div></div>
            <div class="detail-description">{{ $supplierInvoice->description }}</div>
            <dl class="detail-metadata-grid mb-0"><div><dt>Supplier invoice no.</dt><dd>{{ $supplierInvoice->invoice_no }}</dd></div><div><dt>Invoice date</dt><dd>{{ $supplierInvoice->invoice_date->format('j M Y') }}</dd></div><div><dt>Due date</dt><dd>{{ $supplierInvoice->due_date?->format('j M Y') ?? 'Not specified' }}</dd></div><div><dt>Internal reference</dt><dd>{{ $supplierInvoice->internal_no }}</dd></div></dl>
        </div></section>

        <section class="card detail-section"><div class="card-body p-0">
            <div class="detail-section-heading px-4 pt-4"><div><h2>Line items</h2><p>{{ $supplierInvoice->items->count() }} {{ Str::plural('line', $supplierInvoice->items->count()) }} recorded on this invoice.</p></div></div>
            <div class="table-responsive"><table class="table detail-table align-middle mb-0"><thead><tr><th>Description</th><th class="text-end">Quantity</th><th class="text-end">Unit price</th><th class="text-end">Subtotal</th></tr></thead><tbody>
                @foreach ($supplierInvoice->items as $item)<tr><td class="fw-semibold text-body">{{ $item->description }}</td><td class="text-end">{{ rtrim(rtrim($item->quantity, '0'), '.') }}</td><td class="text-end">{{ App\Support\Money::of($item->unit_price)->format($supplierInvoice->currency) }}</td><td class="text-end fw-semibold">{{ App\Support\Money::of($item->subtotal)->format($supplierInvoice->currency) }}</td></tr>@endforeach
            </tbody></table></div>
            <div class="money-summary"><dl><div><dt>Subtotal</dt><dd>{{ App\Support\Money::of($supplierInvoice->subtotal)->format($supplierInvoice->currency) }}</dd></div><div><dt>Tax</dt><dd>{{ App\Support\Money::of($supplierInvoice->tax_amount)->format($supplierInvoice->currency) }}</dd></div><div class="money-summary-total"><dt>Total</dt><dd>{{ App\Support\Money::of($supplierInvoice->total_amount)->format($supplierInvoice->currency) }}</dd></div></dl></div>
        </div></section>

        <section class="card detail-section"><div class="card-body">
            <div class="detail-section-heading"><div><h2>Invoice documents</h2><p>Private source documents used to verify this invoice.</p></div></div>
            <div class="attachment-list">
                @forelse ($supplierInvoice->attachments as $attachment)
                    <div class="attachment-item"><div class="attachment-icon" aria-hidden="true">INV</div><div class="attachment-copy"><a href="{{ route('attachments.download', $attachment) }}">{{ $attachment->original_name }}</a><small>{{ number_format($attachment->size / 1024, 0) }} KB · Uploaded by {{ $attachment->uploadedBy?->name ?? 'Unknown' }}</small></div>@can('delete', $attachment)<form method="POST" action="{{ route('attachments.destroy', $attachment) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger text-decoration-none" type="submit">Delete</button></form>@endcan</div>
                @empty<x-empty-state title="Invoice document required" description="Attach the supplier invoice document before submission." />@endforelse
            </div>
            @can('addAttachment', $supplierInvoice)<form class="attachment-upload" method="POST" enctype="multipart/form-data" action="{{ route('supplier-invoice-attachments.store', $supplierInvoice) }}">@csrf<div class="flex-grow-1"><label class="form-label" for="attachment">Add invoice document</label><input class="form-control" id="attachment" name="attachment" type="file" required></div><button class="btn btn-outline-primary" type="submit">Upload</button></form>@endcan
        </div></section>

        @foreach ($supplierInvoice->approvalInstances as $approvalInstance)<x-approval-timeline :instance="$approvalInstance" />@endforeach
    </main>

    <aside class="detail-sidebar">
        <div class="card detail-summary-card"><div class="card-body">
            <div class="detail-summary-status"><span>Invoice status</span><x-status-badge :status="$supplierInvoice->status" /></div>
            <div class="detail-total"><span>Invoice total</span><strong>{{ App\Support\Money::of($supplierInvoice->total_amount)->format($supplierInvoice->currency) }}</strong></div>
            <dl class="detail-summary-list"><div><dt>Vendor</dt><dd>{{ $supplierInvoice->vendor->name }}</dd></div><div><dt>Entered by</dt><dd>{{ $supplierInvoice->submittedBy->name }}</dd></div><div><dt>Department</dt><dd>{{ $supplierInvoice->department->name }}</dd></div><div><dt>Category</dt><dd>{{ $supplierInvoice->category->name }}</dd></div><div><dt>Due date</dt><dd>{{ $supplierInvoice->due_date?->format('j M Y') ?? 'Not specified' }}</dd></div>@if ($supplierInvoice->submitted_at)<div><dt>Submitted</dt><dd>{{ $supplierInvoice->submitted_at->format('j M Y, H:i') }}</dd></div>@endif</dl>
        </div></div>
        @can('delete', $supplierInvoice)<div class="card detail-danger-card"><div class="card-body"><h2>Delete draft</h2><p>This removes the draft, its items, and attachments. The internal number will not be reused.</p><form method="POST" action="{{ route('supplier-invoices.destroy', $supplierInvoice) }}">@csrf @method('DELETE')<button class="btn btn-outline-danger w-100" type="submit">Delete draft</button></form></div></div>@endcan
    </aside>
</div>
@endsection
