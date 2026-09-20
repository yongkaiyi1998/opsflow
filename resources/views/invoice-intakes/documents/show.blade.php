@extends('layouts.app')
@section('title', 'Invoice verification')
@section('content')
@php
    $candidate = is_array($documentIntake->extraction_payload) ? $documentIntake->extraction_payload : [];
    $candidateItems = collect($candidate['line_items'] ?? [])->map(fn ($item) => [
        'description' => $item['description'] ?? '',
        'quantity' => $item['quantity'] ?? '1.0000',
        'unit_price' => $item['unit_price'] ?? '',
    ])->all();
    $formDefaults = [
        'invoice_no' => $candidate['invoice_no'] ?? '',
        'invoice_date' => $candidate['invoice_date'] ?? '',
        'due_date' => $candidate['due_date'] ?? '',
        'description' => '',
        'tax_amount' => $candidate['tax_amount'] ?? '0.00',
        'items' => $candidateItems ?: [['description' => '', 'quantity' => '1.0000', 'unit_price' => '']],
    ];
@endphp
<div class="workflow-breadcrumb">
    <a href="{{ route('invoice-intakes.index') }}">Invoice intake</a><span>/</span>
    <a href="{{ route('invoice-intakes.show', $intakeBatch) }}">Batch #{{ str_pad((string) $intakeBatch->id, 6, '0', STR_PAD_LEFT) }}</a><span>/</span>
    <span>{{ $documentIntake->original_name }}</span>
</div>
<x-page-header :title="$documentIntake->original_name" description="Review AI candidate data and confirm the authoritative Supplier Invoice values.">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('invoice-intakes.documents.original', [$intakeBatch, $documentIntake]) }}">Open original</a>
        @if (config('ai.enabled') && in_array($documentIntake->status, [App\DocumentIntakeStatus::Pending, App\DocumentIntakeStatus::Processing, App\DocumentIntakeStatus::Failed], true))
            <form method="POST" action="{{ route('invoice-intakes.documents.extract', [$intakeBatch, $documentIntake]) }}">
                @csrf
                <button class="btn btn-primary" type="submit">{{ $documentIntake->status === App\DocumentIntakeStatus::Pending ? 'Process document' : 'Retry extraction' }}</button>
            </form>
        @endif
    </x-slot:actions>
</x-page-header>

@error('verification')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror

@if ($documentIntake->status === App\DocumentIntakeStatus::Verified)
    <div class="card"><div class="card-body p-4 p-lg-5">
        <div class="d-flex align-items-start gap-3">
            <span class="status-badge status-approved">Verified</span>
            <div>
                <h2 class="h4 mb-2">Supplier Invoice draft created</h2>
                <p class="text-secondary mb-3">This intake was verified{{ $documentIntake->verifiedBy ? ' by '.$documentIntake->verifiedBy->name : '' }}{{ $documentIntake->verified_at ? ' on '.$documentIntake->verified_at->format('j M Y, g:i A') : '' }}. The draft has not been submitted for approval.</p>
                @if ($documentIntake->supplierInvoice)
                    <a class="btn btn-primary" href="{{ route('supplier-invoices.show', $documentIntake->supplierInvoice) }}">Open {{ $documentIntake->supplierInvoice->internal_no }}</a>
                @endif
            </div>
        </div>
    </div></div>
@elseif ($documentIntake->status === App\DocumentIntakeStatus::NeedsVerification && $candidate !== [])
    @if (! empty($documentIntake->extraction_warnings))
        <div class="alert alert-warning" role="alert">
            <strong>Review these extraction checks</strong>
            <ul class="mb-0 mt-2">
                @foreach ($documentIntake->extraction_warnings as $warning)
                    <li>{{ $warning['message'] ?? 'Review the extracted value against the original document.' }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row g-4 align-items-start">
        <div class="col-xl-8">
            <div class="card mb-4"><div class="card-header"><h2 class="h6 mb-0">AI extracted candidate</h2></div><div class="card-body">
                <p class="small text-secondary">Candidate values are untrusted reference data. Review them against the original document and correct the editable fields below.</p>
                <div class="row g-3">
                    @foreach (['Vendor name' => $candidate['vendor_name'] ?? null, 'Invoice number' => $candidate['invoice_no'] ?? null, 'Invoice date' => $candidate['invoice_date'] ?? null, 'Due date' => $candidate['due_date'] ?? null] as $label => $value)
                        <div class="col-sm-6"><span class="detail-label">{{ $label }}</span><strong class="text-break">{{ $value ?? 'Not found' }}</strong></div>
                    @endforeach
                </div>
            </div></div>

            @if ($vendorMatches !== [])
                <div class="card mb-4"><div class="card-header"><h2 class="h6 mb-0">Vendor suggestions</h2></div><div class="card-body">
                    <p class="small text-secondary">Suggestions use deterministic name matching. Select a suggestion explicitly or choose any active vendor in the form.</p>
                    <div class="vstack gap-2">
                        @foreach ($vendorMatches as $match)
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 border rounded-3 p-3">
                                <div>
                                    <div class="d-flex align-items-center gap-2"><strong>{{ $match['vendor']->name }}</strong><span class="badge text-bg-light">{{ str($match['classification']->value)->lower()->title() }}</span></div>
                                    <small class="text-secondary">{{ implode(' ', $match['reasons']) }}</small>
                                </div>
                                <button class="btn btn-sm btn-outline-primary apply-vendor-match" type="button" data-vendor-id="{{ $match['vendor']->id }}">Select vendor</button>
                            </div>
                        @endforeach
                    </div>
                </div></div>
            @endif

            @if ($duplicateMatches !== [])
                <div class="card mb-4"><div class="card-header"><h2 class="h6 mb-0">Possible duplicates</h2></div><div class="card-body">
                    <p class="small text-secondary">These warnings are based on deterministic evidence. Compare the records before creating the draft.</p>
                    <div class="vstack gap-3">
                        @foreach ($duplicateMatches as $match)
                            @php
                                $duplicateBadge = match ($match['classification']) {
                                    App\DuplicateMatchClassification::Exact => 'text-bg-danger',
                                    App\DuplicateMatchClassification::High => 'text-bg-warning',
                                    default => 'text-bg-light',
                                };
                            @endphp
                            <div class="border rounded-3 p-3">
                                <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-2">
                                    <div>
                                        <span class="badge {{ $duplicateBadge }}">{{ $match['classification']->value }}</span>
                                        <strong class="d-block mt-2">
                                            @if ($match['record_type'] === 'supplier_invoice')
                                                {{ $match['record']->internal_no }} · {{ $match['invoice_no'] ?? 'Invoice number unavailable' }}
                                            @else
                                                {{ $match['record']->original_name }} · {{ $match['invoice_no'] ?? 'Invoice number unavailable' }}
                                            @endif
                                        </strong>
                                        <small class="text-secondary">{{ $match['vendor_name'] ?? 'Vendor unavailable' }} · MYR {{ $match['total_amount'] ?? '—' }} · {{ $match['invoice_date'] ?? 'Date unavailable' }}</small>
                                        @if ($match['record_type'] === 'document_intake')
                                            <small class="d-block text-secondary">Batch #{{ str_pad((string) $match['record']->intake_batch_id, 6, '0', STR_PAD_LEFT) }}</small>
                                        @endif
                                    </div>
                                    @if ($match['record_type'] === 'supplier_invoice')
                                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('supplier-invoices.show', $match['record']) }}">Open invoice</a>
                                    @else
                                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('invoice-intakes.documents.show', [$match['record']->intakeBatch, $match['record']]) }}">Open intake</a>
                                    @endif
                                </div>
                                <ul class="small text-secondary mb-0 ps-3">
                                    @foreach ($match['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </div></div>
            @endif

            <div class="card form-card"><div class="card-body">
                <form method="POST" action="{{ route('invoice-intakes.documents.verify', [$intakeBatch, $documentIntake]) }}">
                    @csrf
                    @include('supplier-invoices._form', [
                        'supplierInvoice' => null,
                        'formDefaults' => $formDefaults,
                        'submitLabel' => 'Verify and create draft',
                        'cancelUrl' => route('invoice-intakes.show', $intakeBatch),
                        'categorySuggestionUrl' => route('invoice-intakes.documents.category-suggestion', [$intakeBatch, $documentIntake]),
                    ])
                </form>
            </div></div>
        </div>
        <div class="col-xl-4">
            <div class="card position-sticky" style="top: 5.5rem"><div class="card-header"><h2 class="h6 mb-0">Verification summary</h2></div><div class="card-body">
                <div class="vstack gap-3 mb-4">
                    <div><span class="detail-label">Status</span><x-status-badge :status="$documentIntake->status" /></div>
                    <div><span class="detail-label">Batch</span><strong>#{{ str_pad((string) $intakeBatch->id, 6, '0', STR_PAD_LEFT) }}</strong></div>
                    <div><span class="detail-label">Document</span><strong>{{ $documentIntake->typeLabel() }} · {{ Illuminate\Support\Number::fileSize($documentIntake->size, 1) }}</strong></div>
                </div>
                <h3 class="h6">Extracted totals for comparison</h3>
                <dl class="row small mb-3">
                    <dt class="col-6 text-secondary">Subtotal</dt><dd class="col-6 text-end">MYR {{ $candidate['subtotal'] ?? '—' }}</dd>
                    <dt class="col-6 text-secondary">Tax</dt><dd class="col-6 text-end">MYR {{ $candidate['tax_amount'] ?? '—' }}</dd>
                    <dt class="col-6">Total</dt><dd class="col-6 text-end fw-semibold">MYR {{ $candidate['total_amount'] ?? '—' }}</dd>
                </dl>
                <h3 class="h6 pt-3 border-top">Current review calculation</h3>
                <dl class="row small mb-3" aria-live="polite">
                    <dt class="col-6 text-secondary">Subtotal</dt><dd class="col-6 text-end" id="review-subtotal">MYR —</dd>
                    <dt class="col-6 text-secondary">Tax</dt><dd class="col-6 text-end" id="review-tax">MYR —</dd>
                    <dt class="col-6">Total</dt><dd class="col-6 text-end fw-semibold" id="review-total">MYR —</dd>
                </dl>
                <div class="alert alert-info small mb-0">This preview helps comparison. OpsFlow recalculates every value on the server before saving the authoritative draft.</div>
            </div></div>
        </div>
    </div>
@else
    <div class="card"><div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-4 mb-4">
            <div><span class="detail-label">Status</span><x-status-badge :status="$documentIntake->status" /></div>
            <div><span class="detail-label">Type</span><strong>{{ $documentIntake->typeLabel() }}</strong></div>
            <div><span class="detail-label">Size</span><strong>{{ Illuminate\Support\Number::fileSize($documentIntake->size, 1) }}</strong></div>
        </div>
        @if (! config('ai.enabled') && $documentIntake->status === App\DocumentIntakeStatus::Pending)
            <div class="alert alert-info mb-0">AI processing is disabled. This document remains safely pending.</div>
        @elseif ($documentIntake->status === App\DocumentIntakeStatus::Processing)
            <div class="alert alert-info mb-0">Extraction is processing. Refresh this page after the queue worker completes.</div>
        @elseif ($documentIntake->status === App\DocumentIntakeStatus::Failed)
            <div class="alert alert-danger mb-0"><strong>Extraction failed.</strong><div class="mt-1">{{ $documentIntake->failure_reason ?? 'The document could not be extracted.' }}</div></div>
        @else
            <div class="alert alert-info mb-0">This document is not available for verification.</div>
        @endif
    </div></div>
@endif
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form[action$="/verify"]');
    if (!form) return;

    const parseScaled = (value, scale) => {
        const pattern = scale === 2 ? /^(\d+)(?:\.(\d{1,2}))?$/ : /^(\d+)(?:\.(\d{1,4}))?$/;
        const match = String(value).trim().match(pattern);
        if (!match) return null;
        return (BigInt(match[1]) * (10n ** BigInt(scale))) + BigInt((match[2] || '').padEnd(scale, '0'));
    };
    const formatMoney = (minorUnits) => `MYR ${(minorUnits / 100n).toLocaleString()}.${String(minorUnits % 100n).padStart(2, '0')}`;
    const updateSummary = () => {
        let subtotal = 0n;
        let valid = true;
        form.querySelectorAll('.invoice-item-row').forEach((row) => {
            const quantity = parseScaled(row.querySelector('[name$="[quantity]"]').value, 4);
            const price = parseScaled(row.querySelector('[name$="[unit_price]"]').value, 2);
            if (quantity === null || price === null) {
                valid = false;
                return;
            }
            subtotal += ((price * quantity) + 5000n) / 10000n;
        });
        const tax = parseScaled(form.querySelector('#tax_amount').value, 2);
        const fallback = 'MYR —';
        document.getElementById('review-subtotal').textContent = valid ? formatMoney(subtotal) : fallback;
        document.getElementById('review-tax').textContent = tax === null ? fallback : formatMoney(tax);
        document.getElementById('review-total').textContent = valid && tax !== null ? formatMoney(subtotal + tax) : fallback;
    };

    form.addEventListener('input', updateSummary);
    form.addEventListener('click', () => requestAnimationFrame(updateSummary));
    document.querySelectorAll('.apply-vendor-match').forEach((button) => {
        button.addEventListener('click', () => {
            const vendorSelect = form.querySelector('#vendor_id');
            vendorSelect.value = button.dataset.vendorId;
            vendorSelect.dispatchEvent(new Event('change', { bubbles: true }));
            vendorSelect.focus();
        });
    });
    updateSummary();
});
</script>
@endpush
