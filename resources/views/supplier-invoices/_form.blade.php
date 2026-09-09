@php
    $savedItems = $supplierInvoice?->items?->map(fn ($item) => ['description' => $item->description, 'quantity' => $item->quantity, 'unit_price' => $item->unit_price])->all();
    $items = old('items', $savedItems ?: [['description' => '', 'quantity' => '1.0000', 'unit_price' => '']]);
@endphp
@if ($supplierInvoice)<input type="hidden" name="lock_version" value="{{ $supplierInvoice->lock_version }}">@endif
<div class="row g-3">
    <div class="col-md-6"><label class="form-label" for="vendor_id">Vendor</label><select class="form-select @error('vendor_id') is-invalid @enderror" id="vendor_id" name="vendor_id" required><option value="">Select vendor</option>@foreach ($vendors as $vendor)<option value="{{ $vendor->id }}" @selected((string) old('vendor_id', $supplierInvoice?->vendor_id) === (string) $vendor->id)>{{ $vendor->name }}</option>@endforeach</select></div>
    <div class="col-md-6"><label class="form-label" for="invoice_no">Supplier invoice number</label><input class="form-control @error('invoice_no') is-invalid @enderror" id="invoice_no" name="invoice_no" value="{{ old('invoice_no', $supplierInvoice?->invoice_no) }}" maxlength="100" required></div>
    <div class="col-md-6"><label class="form-label" for="department_id">Department</label><select class="form-select @error('department_id') is-invalid @enderror" id="department_id" name="department_id" required><option value="">Select department</option>@foreach ($departments as $department)<option value="{{ $department->id }}" @selected((string) old('department_id', $supplierInvoice?->department_id) === (string) $department->id)>{{ $department->name }}</option>@endforeach</select></div>
    <div class="col-md-6"><label class="form-label" for="category_id">Spend category</label><select class="form-select @error('category_id') is-invalid @enderror" id="category_id" name="category_id" required><option value="">Select category</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected((string) old('category_id', $supplierInvoice?->category_id) === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></div>
    <div class="col-md-6"><label class="form-label" for="invoice_date">Invoice date</label><input class="form-control @error('invoice_date') is-invalid @enderror" id="invoice_date" name="invoice_date" type="date" value="{{ old('invoice_date', $supplierInvoice?->invoice_date?->toDateString()) }}" required></div>
    <div class="col-md-6"><label class="form-label" for="due_date">Due date</label><input class="form-control @error('due_date') is-invalid @enderror" id="due_date" name="due_date" type="date" value="{{ old('due_date', $supplierInvoice?->due_date?->toDateString()) }}"></div>
    <div class="col-12"><label class="form-label" for="description">Description</label><textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="4" maxlength="10000" required>{{ old('description', $supplierInvoice?->description) }}</textarea></div>
</div>

<div class="d-flex justify-content-between align-items-center mt-4 mb-2"><h2 class="h5 mb-0">Line items</h2><button class="btn btn-sm btn-outline-primary" id="add-invoice-item" type="button">Add item</button></div>
<div class="vstack gap-2" id="invoice-items">
    @foreach ($items as $index => $item)
        <div class="row g-2 align-items-end invoice-item-row border rounded p-2 mx-0">
            <div class="col-md-6"><label class="form-label" for="item-description-{{ $index }}">Description</label><input class="form-control" id="item-description-{{ $index }}" name="items[{{ $index }}][description]" value="{{ $item['description'] ?? '' }}" maxlength="255" required></div>
            <div class="col-md-2"><label class="form-label" for="item-quantity-{{ $index }}">Quantity</label><input class="form-control" id="item-quantity-{{ $index }}" name="items[{{ $index }}][quantity]" inputmode="decimal" value="{{ $item['quantity'] ?? '1.0000' }}" required></div>
            <div class="col-md-3"><label class="form-label" for="item-price-{{ $index }}">Unit price (MYR)</label><input class="form-control" id="item-price-{{ $index }}" name="items[{{ $index }}][unit_price]" inputmode="decimal" value="{{ $item['unit_price'] ?? '' }}" required></div>
            <div class="col-md-1"><button class="btn btn-outline-danger remove-invoice-item w-100" type="button" aria-label="Remove item">×</button></div>
        </div>
    @endforeach
</div>
@error('items')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
@error('items.*.description')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
@error('items.*.quantity')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
@error('items.*.unit_price')<div class="text-danger small mt-2">{{ $message }}</div>@enderror

<div class="row justify-content-end mt-4"><div class="col-md-4"><label class="form-label" for="tax_amount">Tax amount (MYR)</label><input class="form-control @error('tax_amount') is-invalid @enderror" id="tax_amount" name="tax_amount" inputmode="decimal" value="{{ old('tax_amount', $supplierInvoice?->tax_amount ?? '0.00') }}" required><div class="form-text">Line subtotals and the final total are calculated by the server.</div></div></div>
<div class="d-flex gap-2 mt-4"><button class="btn btn-primary" type="submit">Save draft</button><a class="btn btn-outline-secondary" href="{{ $supplierInvoice ? route('supplier-invoices.show', $supplierInvoice) : route('supplier-invoices.index') }}">Cancel</a></div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const items = document.getElementById('invoice-items');
    let nextIndex = items.querySelectorAll('.invoice-item-row').length;
    document.getElementById('add-invoice-item').addEventListener('click', () => {
        const index = nextIndex++;
        const row = document.createElement('div');
        row.className = 'row g-2 align-items-end invoice-item-row border rounded p-2 mx-0';
        row.innerHTML = `<div class="col-md-6"><label class="form-label" for="item-description-${index}">Description</label><input class="form-control" id="item-description-${index}" name="items[${index}][description]" maxlength="255" required></div><div class="col-md-2"><label class="form-label" for="item-quantity-${index}">Quantity</label><input class="form-control" id="item-quantity-${index}" name="items[${index}][quantity]" inputmode="decimal" value="1.0000" required></div><div class="col-md-3"><label class="form-label" for="item-price-${index}">Unit price (MYR)</label><input class="form-control" id="item-price-${index}" name="items[${index}][unit_price]" inputmode="decimal" required></div><div class="col-md-1"><button class="btn btn-outline-danger remove-invoice-item w-100" type="button" aria-label="Remove item">×</button></div>`;
        items.appendChild(row);
    });
    items.addEventListener('click', (event) => {
        if (event.target.closest('.remove-invoice-item') && items.querySelectorAll('.invoice-item-row').length > 1) {
            event.target.closest('.invoice-item-row').remove();
        }
    });
});
</script>
@endpush
