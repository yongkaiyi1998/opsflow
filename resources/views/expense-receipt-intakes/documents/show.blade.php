@extends('layouts.app')
@section('title', 'Receipt extraction')
@section('content')
@php($candidate = is_array($documentIntake->extraction_payload) ? $documentIntake->extraction_payload : [])
<div class="workflow-breadcrumb">
    <a href="{{ route('expense-receipt-intakes.index') }}">Receipt intake</a><span>/</span>
    <a href="{{ route('expense-receipt-intakes.show', $intakeBatch) }}">Batch #{{ str_pad((string) $intakeBatch->id, 6, '0', STR_PAD_LEFT) }}</a><span>/</span>
    <span>{{ $documentIntake->original_name }}</span>
</div>
<x-page-header :title="$documentIntake->original_name" description="Review the extracted receipt candidate against the private original before verifying the complete batch.">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('expense-receipt-intakes.documents.original', [$intakeBatch, $documentIntake]) }}">Open original</a>
        @if ($documentIntake->status === App\DocumentIntakeStatus::NeedsVerification)
            <a class="btn btn-primary" href="{{ route('expense-receipt-intakes.verification.create', $intakeBatch) }}">Verify batch</a>
        @endif
        @if (config('ai.enabled') && in_array($documentIntake->status, [App\DocumentIntakeStatus::Pending, App\DocumentIntakeStatus::Processing, App\DocumentIntakeStatus::Failed], true))
            <form method="POST" action="{{ route('expense-receipt-intakes.documents.extract', [$intakeBatch, $documentIntake]) }}">
                @csrf
                <button class="btn btn-primary" type="submit">{{ $documentIntake->status === App\DocumentIntakeStatus::Failed ? 'Retry extraction' : 'Process receipt' }}</button>
            </form>
        @endif
    </x-slot:actions>
</x-page-header>

@if ($documentIntake->status === App\DocumentIntakeStatus::NeedsVerification && $candidate !== [])
    @if (! empty($documentIntake->extraction_warnings))
        <div class="alert alert-warning" role="alert">
            <strong>Review these extraction checks</strong>
            <ul class="mb-0 mt-2">@foreach ($documentIntake->extraction_warnings as $warning)<li>{{ $warning['message'] ?? 'Review this value against the original receipt.' }}</li>@endforeach</ul>
        </div>
    @endif
    <div class="card"><div class="card-header"><h2 class="h6 mb-0">Extracted receipt candidate</h2></div><div class="card-body p-4">
        <p class="small text-secondary mb-4">These values are untrusted, read-only candidate data. No Expense Claim or Expense Claim item has been created.</p>
        <div class="row g-4">
            @foreach (['Merchant' => $candidate['merchant'] ?? null, 'Transaction date' => $candidate['transaction_date'] ?? null, 'Description' => $candidate['description'] ?? null, 'Currency' => $candidate['currency'] ?? null, 'Gross amount' => $candidate['amount'] ?? null, 'Tax (informational)' => $candidate['tax_amount'] ?? null] as $label => $value)
                <div class="col-sm-6 col-xl-4"><span class="detail-label">{{ $label }}</span><strong class="text-break">{{ $value ?? 'Not found' }}</strong></div>
            @endforeach
        </div>
    </div></div>
@else
    <div class="card"><div class="card-body p-4">
        <div class="d-flex flex-wrap align-items-center gap-4 mb-4">
            <div><span class="detail-label">Status</span><x-status-badge :status="$documentIntake->status" /></div>
            <div><span class="detail-label">Type</span><strong>{{ $documentIntake->typeLabel() }}</strong></div>
            <div><span class="detail-label">Size</span><strong>{{ Illuminate\Support\Number::fileSize($documentIntake->size, 1) }}</strong></div>
        </div>
        @if (! config('ai.enabled') && $documentIntake->status === App\DocumentIntakeStatus::Pending)
            <div class="alert alert-info mb-0">AI processing is disabled. This receipt remains safely pending.</div>
        @elseif ($documentIntake->status === App\DocumentIntakeStatus::Processing)
            <div class="alert alert-info mb-0">Extraction is processing. Refresh after the queue worker completes.</div>
        @elseif ($documentIntake->status === App\DocumentIntakeStatus::Failed)
            <div class="alert alert-danger mb-0"><strong>Extraction failed.</strong><div class="mt-1">{{ $documentIntake->failure_reason ?? 'The receipt could not be extracted.' }}</div></div>
        @else
            <div class="alert alert-info mb-0">This receipt is waiting for extraction.</div>
        @endif
    </div></div>
@endif
@endsection
