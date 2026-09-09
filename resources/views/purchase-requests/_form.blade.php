@php
    $savedItems = $purchaseRequest?->items?->map(fn ($item) => ['description' => $item->description, 'quantity' => $item->quantity, 'unit_price' => $item->unit_price])->all();
    $items = old('items', $savedItems ?: [['description' => '', 'quantity' => 1, 'unit_price' => '']]);
@endphp
@if ($purchaseRequest)<input type="hidden" name="lock_version" value="{{ $purchaseRequest->lock_version }}">@endif
<div class="row g-3">
    <div class="col-md-8"><label class="form-label" for="title">Title</label><input class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ old('title', $purchaseRequest?->title) }}" maxlength="255" required></div>
    <div class="col-md-4"><label class="form-label">Department</label><input class="form-control" value="{{ auth()->user()->department?->name ?? 'No department assigned' }}" disabled><div class="form-text">Derived from your user profile.</div></div>
    <div class="col-12"><label class="form-label" for="description">Business justification</label><textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="4" maxlength="10000" required>{{ old('description', $purchaseRequest?->description) }}</textarea></div>
    <div class="col-md-4"><label class="form-label" for="category_id">Spend category</label><select class="form-select @error('category_id') is-invalid @enderror" id="category_id" name="category_id" required><option value="">Select category</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected((string) old('category_id', $purchaseRequest?->category_id) === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></div>
    <div class="col-md-4"><label class="form-label" for="vendor_id">Vendor</label><select class="form-select @error('vendor_id') is-invalid @enderror" id="vendor_id" name="vendor_id"><option value="">Not selected</option>@foreach ($vendors as $vendor)<option value="{{ $vendor->id }}" @selected((string) old('vendor_id', $purchaseRequest?->vendor_id) === (string) $vendor->id)>{{ $vendor->name }}</option>@endforeach</select></div>
    <div class="col-md-4"><label class="form-label" for="needed_by_date">Needed by</label><input class="form-control @error('needed_by_date') is-invalid @enderror" id="needed_by_date" name="needed_by_date" type="date" min="{{ today()->toDateString() }}" value="{{ old('needed_by_date', $purchaseRequest?->needed_by_date?->toDateString()) }}"></div>
</div>

<div class="d-flex justify-content-between align-items-center mt-4 mb-2"><h2 class="h5 mb-0">Items</h2><button class="btn btn-sm btn-outline-primary" id="add-item" type="button">Add item</button></div>
<div class="vstack gap-2" id="items">
    @foreach ($items as $index => $item)
        <div class="row g-2 align-items-end item-row border rounded p-2 mx-0">
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

<div class="row justify-content-end mt-4"><div class="col-md-4"><label class="form-label" for="tax_amount">Tax amount (MYR)</label><input class="form-control @error('tax_amount') is-invalid @enderror" id="tax_amount" name="tax_amount" inputmode="decimal" value="{{ old('tax_amount', $purchaseRequest?->tax_amount ?? '0.00') }}" required><div class="form-text">Item subtotals and the final total are calculated by the server.</div></div></div>
<div class="d-flex gap-2 mt-4"><button class="btn btn-primary" type="submit">Save draft</button><a class="btn btn-outline-secondary" href="{{ $purchaseRequest ? route('purchase-requests.show', $purchaseRequest) : route('purchase-requests.index') }}">Cancel</a></div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const items = document.getElementById('items');
    let nextIndex = items.querySelectorAll('.item-row').length;
    document.getElementById('add-item').addEventListener('click', () => {
        const index = nextIndex++;
        const row = document.createElement('div');
        row.className = 'row g-2 align-items-end item-row border rounded p-2 mx-0';
        row.innerHTML = `<div class="col-md-6"><label class="form-label" for="item-description-${index}">Description</label><input class="form-control" id="item-description-${index}" name="items[${index}][description]" maxlength="255" required></div><div class="col-md-2"><label class="form-label" for="item-quantity-${index}">Quantity</label><input class="form-control" id="item-quantity-${index}" name="items[${index}][quantity]" type="number" min="1" max="1000000" step="1" value="1" required></div><div class="col-md-3"><label class="form-label" for="item-price-${index}">Unit price (MYR)</label><input class="form-control" id="item-price-${index}" name="items[${index}][unit_price]" inputmode="decimal" required></div><div class="col-md-1"><button class="btn btn-outline-danger remove-item w-100" type="button" aria-label="Remove item">×</button></div>`;
        items.appendChild(row);
    });
    items.addEventListener('click', (event) => {
        if (event.target.closest('.remove-item') && items.querySelectorAll('.item-row').length > 1) {
            event.target.closest('.item-row').remove();
        }
    });
});
</script>
@endpush
