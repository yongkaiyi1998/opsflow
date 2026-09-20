@extends('layouts.app')
@section('title', 'Verify receipt batch')
@section('content')
@php($ready = $intakeBatch->documentIntakes->isNotEmpty() && $intakeBatch->documentIntakes->every(fn ($document) => $document->status === App\DocumentIntakeStatus::NeedsVerification))
<div class="workflow-breadcrumb"><a href="{{ route('expense-receipt-intakes.index') }}">Receipt intake</a><span>/</span><a href="{{ route('expense-receipt-intakes.show', $intakeBatch) }}">Batch #{{ str_pad((string) $intakeBatch->id, 6, '0', STR_PAD_LEFT) }}</a><span>/</span><span>Verify</span></div>
<x-page-header title="Verify receipt batch" description="Confirm every receipt before creating one Expense Claim draft.">
    <x-slot:actions><a class="btn btn-outline-secondary" href="{{ route('expense-receipt-intakes.show', $intakeBatch) }}">Back to batch</a></x-slot:actions>
</x-page-header>

@if (! $ready)
    <div class="alert alert-warning">Every receipt must finish extraction and be ready for verification. Retry failed receipts or wait for processing to finish.</div>
@else
<form method="POST" action="{{ route('expense-receipt-intakes.verification.store', $intakeBatch) }}">
    @csrf
    <div class="card mb-4"><div class="card-header"><h2 class="h6 mb-0">Claim details</h2></div><div class="card-body p-4">
        <div class="row g-3">
            <div class="col-12"><label class="form-label" for="title">Title</label><input class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ old('title') }}" required>@error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-12"><label class="form-label" for="description">Description</label><textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="3" required>{{ old('description') }}</textarea>@error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-md-6 d-flex flex-column gap-1"><span class="detail-label">Employee</span><strong>{{ $intakeBatch->uploadedBy->name }}</strong></div>
            <div class="col-md-6 d-flex flex-column gap-1"><span class="detail-label">Department</span><strong>{{ $intakeBatch->uploadedBy->department?->name ?? 'Unavailable' }}</strong></div>
        </div>
    </div></div>

    @error('verification')<div class="alert alert-danger">{{ $message }}</div>@enderror
    @error('receipts')<div class="alert alert-danger">{{ $message }}</div>@enderror

    @foreach ($intakeBatch->documentIntakes as $document)
        @php($candidate = is_array($document->extraction_payload) ? $document->extraction_payload : [])
        @php($field = "receipts.{$document->id}")
        <div class="card mb-4"><div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2"><h2 class="h6 mb-0">Receipt {{ $loop->iteration }}: {{ $document->original_name }}</h2><a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="{{ route('expense-receipt-intakes.documents.original', [$intakeBatch, $document]) }}">Open original</a></div><div class="card-body p-4">
            @if (! empty($document->extraction_warnings))<div class="alert alert-warning"><strong>Review against the original</strong><ul class="mb-0 mt-2">@foreach ($document->extraction_warnings as $warning)<li>{{ $warning['message'] ?? 'Review this value.' }}</li>@endforeach</ul></div>@endif
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label" for="merchant-{{ $document->id }}">Merchant</label><input class="form-control @error("{$field}.merchant") is-invalid @enderror" id="merchant-{{ $document->id }}" name="receipts[{{ $document->id }}][merchant]" value="{{ old("{$field}.merchant", $candidate['merchant'] ?? '') }}">@error("{$field}.merchant")<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-md-6"><label class="form-label" for="date-{{ $document->id }}">Expense date</label><input class="form-control @error("{$field}.expense_date") is-invalid @enderror" type="date" id="date-{{ $document->id }}" name="receipts[{{ $document->id }}][expense_date]" value="{{ old("{$field}.expense_date", $candidate['transaction_date'] ?? '') }}" required>@error("{$field}.expense_date")<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-md-6"><label class="form-label" for="category-{{ $document->id }}">Spend category</label><select class="form-select @error("{$field}.category_id") is-invalid @enderror" id="category-{{ $document->id }}" name="receipts[{{ $document->id }}][category_id]" required><option value="">Select a category</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected((string) old("{$field}.category_id") === (string) $category->id)>{{ $category->name }}</option>@endforeach</select>@error("{$field}.category_id")<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-md-6"><label class="form-label" for="description-{{ $document->id }}">Description</label><input class="form-control @error("{$field}.description") is-invalid @enderror" id="description-{{ $document->id }}" name="receipts[{{ $document->id }}][description]" value="{{ old("{$field}.description", $candidate['description'] ?? '') }}" required>@error("{$field}.description")<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-md-6"><label class="form-label" for="amount-{{ $document->id }}">Gross amount (MYR)</label><input class="form-control js-receipt-amount @error("{$field}.amount") is-invalid @enderror" inputmode="decimal" id="amount-{{ $document->id }}" name="receipts[{{ $document->id }}][amount]" value="{{ old("{$field}.amount", $candidate['amount'] ?? '') }}" required>@error("{$field}.amount")<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-md-6"><label class="form-label" for="tax-{{ $document->id }}">Tax (informational)</label><input class="form-control @error("{$field}.tax_amount") is-invalid @enderror" inputmode="decimal" id="tax-{{ $document->id }}" name="receipts[{{ $document->id }}][tax_amount]" value="{{ old("{$field}.tax_amount", $candidate['tax_amount'] ?? '0.00') }}" required><div class="form-text">Tax is included in the gross amount and is not added again.</div>@error("{$field}.tax_amount")<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            </div>
        </div></div>
    @endforeach

    <div class="card"><div class="card-body p-4 d-flex flex-wrap align-items-center justify-content-between gap-3"><div><strong>{{ $intakeBatch->documentIntakes->count() }} receipt item(s) · MYR <span id="claim-total-preview">0.00</span></strong><div class="small text-secondary">This is a preview. The server recalculates the authoritative total. Creating the draft does not submit it for approval.</div></div><button class="btn btn-primary" type="submit">Create Expense Claim draft</button></div></div>
</form>
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const amounts = [...document.querySelectorAll('.js-receipt-amount')];
    const output = document.getElementById('claim-total-preview');
    const cents = value => {
        const match = value.trim().match(/^(\d{1,13})(?:\.(\d{0,2}))?$/);
        return match ? (BigInt(match[1]) * 100n) + BigInt((match[2] || '').padEnd(2, '0')) : 0n;
    };
    const refresh = () => {
        const total = amounts.reduce((sum, input) => sum + cents(input.value), 0n);
        output.textContent = `${total / 100n}.${String(total % 100n).padStart(2, '0')}`;
    };
    amounts.forEach(input => input.addEventListener('input', refresh));
    refresh();
});
</script>
@endpush
@endif
@endsection
