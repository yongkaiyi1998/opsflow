@extends('layouts.app')
@section('title', 'Verify quotation')
@section('content')
@php
    $candidateItems = collect($candidate['line_items'] ?? [])->map(fn ($item) => [
        'description' => $item['description'] ?? '',
        'quantity' => $item['quantity'] ?? 1,
        'unit_price' => $item['unit_price'] ?? '',
    ])->all();
    $items = old('items', $candidateItems ?: [['description' => '', 'quantity' => 1, 'unit_price' => '']]);
    $defaultTitle = filled($candidate['vendor_name'] ?? null) ? 'Purchase from '.str($candidate['vendor_name'])->limit(200) : '';
    $defaultDescription = collect([
        filled($candidate['quotation_no'] ?? null) ? 'Quotation '.($candidate['quotation_no'] ?? '') : null,
        filled($candidate['vendor_name'] ?? null) ? 'from '.($candidate['vendor_name'] ?? '') : null,
    ])->filter()->join(' ');
@endphp

<div class="workflow-breadcrumb">
    <a href="{{ route('purchase-quotation-intakes.index') }}">Quotation intake</a><span>/</span>
    <a href="{{ route('purchase-quotation-intakes.show', $intakeBatch) }}">Batch #{{ str_pad((string) $intakeBatch->id, 6, '0', STR_PAD_LEFT) }}</a><span>/</span>
    <a href="{{ route('purchase-quotation-intakes.documents.show', [$intakeBatch, $documentIntake]) }}">{{ $documentIntake->original_name }}</a><span>/</span><span>Verify</span>
</div>

<x-page-header title="Verify quotation" description="Review the extracted candidate, correct it against the private original, and create an ordinary Purchase Request draft.">
    <x-slot:actions><a class="btn btn-outline-secondary" target="_blank" rel="noopener" href="{{ route('purchase-quotation-intakes.documents.original', [$intakeBatch, $documentIntake]) }}">Open original</a></x-slot:actions>
</x-page-header>

@if (! empty($documentIntake->extraction_warnings))
    <div class="alert alert-warning"><strong>Review these extraction checks</strong><ul class="mb-0 mt-2">@foreach ($documentIntake->extraction_warnings as $warning)<li>{{ $warning['message'] ?? 'Review this value against the original quotation.' }}</li>@endforeach</ul></div>
@endif

<div class="card mb-4"><div class="card-header"><h2 class="h6 mb-0">Quotation context</h2></div><div class="card-body p-4"><div class="row g-4">
    @foreach (['Extracted vendor' => $candidate['vendor_name'] ?? null, 'Quotation number' => $candidate['quotation_no'] ?? null, 'Quotation date' => $candidate['quotation_date'] ?? null, 'Valid until' => $candidate['valid_until'] ?? null, 'Currency' => $candidate['currency'] ?? null, 'Extracted total' => $candidate['total_amount'] ?? null] as $label => $value)
        <div class="col-sm-6 col-xl-4"><span class="detail-label">{{ $label }}</span><strong class="d-block text-break">{{ $value ?? 'Not found' }}</strong></div>
    @endforeach
</div></div></div>

<form method="POST" action="{{ route('purchase-quotation-intakes.documents.verification.store', [$intakeBatch, $documentIntake]) }}">
    @csrf
    <section class="form-section">
        <div class="form-section-heading"><h2>Purchase Request</h2><p>Requester and department are derived from your account. Vendor and category remain human-confirmed.</p></div>
        <div class="row g-3">
            <div class="col-md-8"><label class="form-label" for="title">Title</label><input class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ old('title', $defaultTitle) }}" maxlength="255" required>@error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-md-4"><label class="form-label">Requester</label><input class="form-control" value="{{ auth()->user()->name }}" disabled></div>
            <div class="col-md-8"><label class="form-label" for="description">Business justification</label><textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="4" maxlength="10000" required>{{ old('description', $defaultDescription) }}</textarea>@error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-md-4"><label class="form-label">Department</label><input class="form-control" value="{{ auth()->user()->department?->name ?? 'No department assigned' }}" disabled><div class="form-text">Derived from your user profile.</div></div>
            <div class="col-md-4"><label class="form-label" for="vendor_id">Vendor</label><select class="form-select @error('vendor_id') is-invalid @enderror" id="vendor_id" name="vendor_id"><option value="">Not selected</option>@foreach ($vendors as $vendor)<option value="{{ $vendor->id }}" @selected((string) old('vendor_id') === (string) $vendor->id)>{{ $vendor->name }}</option>@endforeach</select>@error('vendor_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-md-4"><label class="form-label" for="category_id">Spend category</label><select class="form-select @error('category_id') is-invalid @enderror" id="category_id" name="category_id" required><option value="">Select category</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }}</option>@endforeach</select>@if (config('ai.enabled'))<button class="btn btn-sm btn-link px-0 mt-1" type="submit" formaction="{{ route('purchase-requests.category-suggestion.create') }}" formnovalidate>Suggest category</button>@endif @error('category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-md-4"><label class="form-label" for="needed_by_date">Needed by</label><input class="form-control @error('needed_by_date') is-invalid @enderror" id="needed_by_date" name="needed_by_date" type="date" min="{{ today()->toDateString() }}" value="{{ old('needed_by_date') }}">@error('needed_by_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        </div>

        @if ($vendorMatches !== [])
            <div class="border rounded-3 bg-body-tertiary p-3 mt-3"><span class="detail-label">Advisory vendor matches</span><div class="d-flex flex-wrap gap-2 mt-2">@foreach ($vendorMatches as $match)<button class="btn btn-sm btn-outline-secondary use-vendor-match" type="button" data-vendor-id="{{ $match['vendor']->id }}">{{ $match['vendor']->name }} · {{ str($match['classification']->value)->lower()->title() }}</button>@endforeach</div><div class="form-text">Select a match explicitly or choose another active vendor.</div></div>
        @endif
        <x-category-suggestion :suggestion="session('categorySuggestion')" />
    </section>

    <section class="form-section">
        <div class="form-section-heading d-flex flex-wrap justify-content-between align-items-center gap-2"><div><h2>Line items</h2><p>Correct quantities and prices against the quotation. Quantities must be whole numbers.</p></div><button class="btn btn-sm btn-outline-primary" id="add-item" type="button">Add item</button></div>
        <div class="vstack gap-2" id="items">
            @foreach ($items as $index => $item)
                <div class="row g-3 align-items-end item-row line-item-card mx-0">
                    <div class="col-md-6"><label class="form-label" for="item-description-{{ $index }}">Description</label><input class="form-control" id="item-description-{{ $index }}" name="items[{{ $index }}][description]" value="{{ $item['description'] ?? '' }}" maxlength="255" required></div>
                    <div class="col-md-2"><label class="form-label" for="item-quantity-{{ $index }}">Quantity</label><input class="form-control" id="item-quantity-{{ $index }}" name="items[{{ $index }}][quantity]" type="number" min="1" max="1000000" step="1" value="{{ $item['quantity'] ?? 1 }}" required></div>
                    <div class="col-md-3"><label class="form-label" for="item-price-{{ $index }}">Unit price (MYR)</label><input class="form-control" id="item-price-{{ $index }}" name="items[{{ $index }}][unit_price]" inputmode="decimal" value="{{ $item['unit_price'] ?? '' }}" required></div>
                    <div class="col-md-1"><button class="btn btn-outline-danger remove-item w-100" type="button" aria-label="Remove item">×</button></div>
                </div>
            @endforeach
        </div>
        @error('items')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
        @error('items.*.description')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
        @error('items.*.quantity')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
        @error('items.*.unit_price')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
        <div class="row justify-content-end mt-4"><div class="col-md-5 col-lg-4 form-summary"><label class="form-label" for="tax_amount">Tax amount (MYR)</label><input class="form-control @error('tax_amount') is-invalid @enderror" id="tax_amount" name="tax_amount" inputmode="decimal" value="{{ old('tax_amount', $candidate['tax_amount'] ?? '0.00') }}" required>@error('tax_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror<div class="form-text">Extracted total: {{ $candidate['total_amount'] ?? 'Not found' }}. The server recalculates every line subtotal and the authoritative final total.</div></div></div>
    </section>

    <div class="card mb-4"><div class="card-body p-4 d-flex flex-wrap justify-content-between gap-3"><div><span class="detail-label">Extracted total</span><strong class="d-block">{{ $candidate['total_amount'] ?? 'Not found' }}</strong></div><div><span class="detail-label">Items to verify</span><strong class="d-block" id="item-count">{{ count($items) }}</strong></div><div><span class="detail-label">Authoritative total</span><strong class="d-block">Calculated by OpsFlow on creation</strong></div></div></div>
    <div class="alert alert-info">Creating the draft does not submit it for approval.</div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Create Purchase Request Draft</button><a class="btn btn-outline-secondary" href="{{ route('purchase-quotation-intakes.documents.show', [$intakeBatch, $documentIntake]) }}">Cancel</a></div>
</form>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const items = document.getElementById('items');
    const count = document.getElementById('item-count');
    let nextIndex = items.querySelectorAll('.item-row').length;
    const updateCount = () => { count.textContent = items.querySelectorAll('.item-row').length; };
    document.getElementById('add-item').addEventListener('click', () => {
        const index = nextIndex++;
        const row = document.createElement('div');
        row.className = 'row g-3 align-items-end item-row line-item-card mx-0';
        row.innerHTML = `<div class="col-md-6"><label class="form-label" for="item-description-${index}">Description</label><input class="form-control" id="item-description-${index}" name="items[${index}][description]" maxlength="255" required></div><div class="col-md-2"><label class="form-label" for="item-quantity-${index}">Quantity</label><input class="form-control" id="item-quantity-${index}" name="items[${index}][quantity]" type="number" min="1" max="1000000" step="1" value="1" required></div><div class="col-md-3"><label class="form-label" for="item-price-${index}">Unit price (MYR)</label><input class="form-control" id="item-price-${index}" name="items[${index}][unit_price]" inputmode="decimal" required></div><div class="col-md-1"><button class="btn btn-outline-danger remove-item w-100" type="button" aria-label="Remove item">×</button></div>`;
        items.appendChild(row);
        updateCount();
    });
    items.addEventListener('click', (event) => {
        if (event.target.closest('.remove-item') && items.querySelectorAll('.item-row').length > 1) {
            event.target.closest('.item-row').remove();
            updateCount();
        }
    });
    document.addEventListener('click', (event) => {
        const button = event.target.closest('.use-vendor-match');
        if (! button) return;
        document.getElementById('vendor_id').value = button.dataset.vendorId;
        document.getElementById('vendor_id').focus();
    });
});
</script>
@endpush
@endsection
