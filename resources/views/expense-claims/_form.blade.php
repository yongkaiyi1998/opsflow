@php
    $savedItems = $expenseClaim?->items?->map(fn ($item) => [
        'id' => $item->id,
        'category_id' => $item->category_id,
        'expense_date' => $item->expense_date->toDateString(),
        'merchant' => $item->merchant,
        'description' => $item->description,
        'amount' => $item->amount,
        'tax_amount' => $item->tax_amount,
    ])->all();
    $items = old('items', $savedItems ?: [[
        'category_id' => '', 'expense_date' => today()->toDateString(), 'merchant' => '',
        'description' => '', 'amount' => '', 'tax_amount' => '0.00',
    ]]);
@endphp
@if ($expenseClaim)<input type="hidden" name="lock_version" value="{{ $expenseClaim->lock_version }}">@endif
<div class="row g-3">
    <div class="col-md-8"><label class="form-label" for="title">Claim title</label><input class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ old('title', $expenseClaim?->title) }}" maxlength="255" required></div>
    <div class="col-md-4"><label class="form-label">Employee and department</label><input class="form-control" value="{{ auth()->user()->name }} · {{ auth()->user()->department?->name ?? 'No department assigned' }}" disabled><div class="form-text">Derived from your user profile.</div></div>
    <div class="col-12"><label class="form-label" for="description">Claim description</label><textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="3" maxlength="10000" required>{{ old('description', $expenseClaim?->description) }}</textarea></div>
</div>
<div class="d-flex justify-content-between align-items-center mt-4 mb-2"><h2 class="h5 mb-0">Expense items</h2><button class="btn btn-sm btn-outline-primary" id="add-item" type="button">Add item</button></div>
<p class="text-secondary small">The amount is the gross expense. Tax is informational and is not added to the claim total.</p>
<div class="vstack gap-3" id="items">
    @foreach ($items as $index => $item)
        <div class="row g-2 align-items-end item-row border rounded p-2 mx-0">
            @if (! empty($item['id']))<input type="hidden" name="items[{{ $index }}][id]" value="{{ $item['id'] }}">@endif
            <div class="col-md-3"><label class="form-label" for="item-category-{{ $index }}">Category</label><select class="form-select" id="item-category-{{ $index }}" name="items[{{ $index }}][category_id]" required><option value="">Select</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected((string) ($item['category_id'] ?? '') === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label" for="item-date-{{ $index }}">Expense date</label><input class="form-control" id="item-date-{{ $index }}" name="items[{{ $index }}][expense_date]" type="date" max="{{ today()->toDateString() }}" value="{{ $item['expense_date'] ?? '' }}" required></div>
            <div class="col-md-3"><label class="form-label" for="item-merchant-{{ $index }}">Merchant</label><input class="form-control" id="item-merchant-{{ $index }}" name="items[{{ $index }}][merchant]" maxlength="255" value="{{ $item['merchant'] ?? '' }}"></div>
            <div class="col-md-4"><label class="form-label" for="item-description-{{ $index }}">Description</label><input class="form-control" id="item-description-{{ $index }}" name="items[{{ $index }}][description]" maxlength="255" value="{{ $item['description'] ?? '' }}" required></div>
            <div class="col-md-3"><label class="form-label" for="item-amount-{{ $index }}">Gross amount (MYR)</label><input class="form-control" id="item-amount-{{ $index }}" name="items[{{ $index }}][amount]" inputmode="decimal" value="{{ $item['amount'] ?? '' }}" required></div>
            <div class="col-md-3"><label class="form-label" for="item-tax-{{ $index }}">Tax included (MYR)</label><input class="form-control" id="item-tax-{{ $index }}" name="items[{{ $index }}][tax_amount]" inputmode="decimal" value="{{ $item['tax_amount'] ?? '0.00' }}" required></div>
            <div class="col-md-5"><div class="form-text pb-2">A private receipt is required after the claim is saved.</div></div>
            <div class="col-md-1"><button class="btn btn-outline-danger remove-item w-100" type="button" aria-label="Remove item">×</button></div>
        </div>
    @endforeach
</div>
@error('items')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
@error('items.*.category_id')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
@error('items.*.expense_date')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
@error('items.*.description')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
@error('items.*.amount')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
@error('items.*.tax_amount')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
<div class="d-flex gap-2 mt-4"><button class="btn btn-primary" type="submit">Save draft</button><a class="btn btn-outline-secondary" href="{{ $expenseClaim ? route('expense-claims.show', $expenseClaim) : route('expense-claims.index') }}">Cancel</a></div>
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const items = document.getElementById('items');
    const categoryOptions = @json($categories->map(fn ($category) => ['id' => $category->id, 'name' => $category->name])->values());
    let nextIndex = items.querySelectorAll('.item-row').length;
    document.getElementById('add-item').addEventListener('click', () => {
        const index = nextIndex++;
        const options = categoryOptions.map(category => `<option value="${category.id}">${category.name}</option>`).join('');
        const row = document.createElement('div');
        row.className = 'row g-2 align-items-end item-row border rounded p-2 mx-0';
        row.innerHTML = `<div class="col-md-3"><label class="form-label" for="item-category-${index}">Category</label><select class="form-select" id="item-category-${index}" name="items[${index}][category_id]" required><option value="">Select</option>${options}</select></div><div class="col-md-2"><label class="form-label" for="item-date-${index}">Expense date</label><input class="form-control" id="item-date-${index}" name="items[${index}][expense_date]" type="date" max="{{ today()->toDateString() }}" value="{{ today()->toDateString() }}" required></div><div class="col-md-3"><label class="form-label" for="item-merchant-${index}">Merchant</label><input class="form-control" id="item-merchant-${index}" name="items[${index}][merchant]" maxlength="255"></div><div class="col-md-4"><label class="form-label" for="item-description-${index}">Description</label><input class="form-control" id="item-description-${index}" name="items[${index}][description]" maxlength="255" required></div><div class="col-md-3"><label class="form-label" for="item-amount-${index}">Gross amount (MYR)</label><input class="form-control" id="item-amount-${index}" name="items[${index}][amount]" inputmode="decimal" required></div><div class="col-md-3"><label class="form-label" for="item-tax-${index}">Tax included (MYR)</label><input class="form-control" id="item-tax-${index}" name="items[${index}][tax_amount]" inputmode="decimal" value="0.00" required></div><div class="col-md-5"><div class="form-text pb-2">A private receipt is required after the claim is saved.</div></div><div class="col-md-1"><button class="btn btn-outline-danger remove-item w-100" type="button" aria-label="Remove item">×</button></div>`;
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
