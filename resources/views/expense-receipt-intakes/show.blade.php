@extends('layouts.app')
@section('title', 'Receipt intake batch')
@section('content')
@php($counts = $intakeBatch->statusCounts())
<div class="workflow-breadcrumb"><a href="{{ route('expense-receipt-intakes.index') }}">Receipt intake</a><span>/</span><span>Batch #{{ str_pad((string) $intakeBatch->id, 6, '0', STR_PAD_LEFT) }}</span></div>
<x-page-header :title="'Receipt batch #'.str_pad((string) $intakeBatch->id, 6, '0', STR_PAD_LEFT)" description="Each receipt is processed independently and remains private to you.">
    <x-slot:actions>
        @if ($intakeBatch->expenseClaim)
            <a class="btn btn-primary" href="{{ route('expense-claims.show', $intakeBatch->expenseClaim) }}">Open {{ $intakeBatch->expenseClaim->claim_no }}</a>
        @elseif ($intakeBatch->documentIntakes->isNotEmpty() && $intakeBatch->documentIntakes->every(fn ($document) => $document->status === App\DocumentIntakeStatus::NeedsVerification))
            <a class="btn btn-primary" href="{{ route('expense-receipt-intakes.verification.create', $intakeBatch) }}">Verify receipts</a>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('expense-receipt-intakes.create') }}">Upload another batch</a>
    </x-slot:actions>
</x-page-header>

@if ($intakeBatch->expenseClaim)
    <div class="alert alert-success">This batch was verified and created expense claim <a class="alert-link" href="{{ route('expense-claims.show', $intakeBatch->expenseClaim) }}">{{ $intakeBatch->expenseClaim->claim_no }}</a> as a draft.</div>
@endif

<div class="card mb-4"><div class="card-body d-flex flex-wrap gap-4">
    <div class="d-flex flex-column gap-1"><span class="detail-label">Uploaded</span><strong>{{ $intakeBatch->created_at->format('j M Y, g:i A') }}</strong></div>
    <div class="d-flex flex-column gap-1"><span class="detail-label">Receipts</span><strong>{{ $intakeBatch->documentIntakes->count() }}</strong></div>
    <div class="d-flex flex-column align-items-start gap-1"><span class="detail-label">Batch status</span><x-status-badge :status="$intakeBatch->derivedStatus()" /></div>
</div></div>

<div class="intake-progress-grid mb-4">
    @foreach ([App\DocumentIntakeStatus::Pending, App\DocumentIntakeStatus::Processing, App\DocumentIntakeStatus::NeedsVerification, App\DocumentIntakeStatus::Verified, App\DocumentIntakeStatus::Failed] as $status)
        <div class="card"><div class="card-body"><span>{{ str($status->value)->replace('_', ' ')->title() }}</span><strong>{{ $counts[$status->value] }}</strong></div></div>
    @endforeach
</div>

<div class="card table-card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>File</th><th>Type</th><th>Size</th><th>Uploaded</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    @foreach ($intakeBatch->documentIntakes as $document)
        <tr>
            <td class="fw-medium text-break">{{ $document->original_name }}</td>
            <td>{{ $document->typeLabel() }}</td>
            <td class="text-nowrap">{{ Illuminate\Support\Number::fileSize($document->size, 1) }}</td>
            <td class="text-nowrap">{{ $document->created_at->format('j M Y, g:i A') }}</td>
            <td><x-status-badge :status="$document->status" /></td>
            <td class="text-end"><div class="d-inline-flex flex-wrap justify-content-end gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('expense-receipt-intakes.documents.original', [$intakeBatch, $document]) }}">Open original</a>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('expense-receipt-intakes.documents.show', [$intakeBatch, $document]) }}">View extraction</a>
            </div></td>
        </tr>
    @endforeach
    </tbody>
</table></div></div>
@endsection
