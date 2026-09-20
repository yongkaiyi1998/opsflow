@extends('layouts.app')
@section('title', 'Invoice intake batch')
@section('content')
@php($counts = $intakeBatch->statusCounts())
<div class="workflow-breadcrumb"><a href="{{ route('invoice-intakes.index') }}">Invoice intake</a><span>/</span><span>Batch #{{ str_pad((string) $intakeBatch->id, 6, '0', STR_PAD_LEFT) }}</span></div>
<x-page-header title="Invoice intake batch" description="Original documents are stored privately and remain available for verification.">
    <x-slot:actions><a class="btn btn-primary" href="{{ route('invoice-intakes.create') }}">Upload another batch</a></x-slot:actions>
</x-page-header>

<div class="card intake-overview-card mb-4"><div class="card-body">
    <div><span class="detail-label">Batch</span><strong>#{{ str_pad((string) $intakeBatch->id, 6, '0', STR_PAD_LEFT) }}</strong></div>
    <div><span class="detail-label">Uploaded by</span><strong>{{ $intakeBatch->uploadedBy->name }}</strong></div>
    <div><span class="detail-label">Uploaded</span><strong>{{ $intakeBatch->created_at->format('j M Y, g:i A') }}</strong></div>
    <div><span class="detail-label">Documents</span><strong>{{ $intakeBatch->documentIntakes->count() }}</strong></div>
    <div><span class="detail-label">Batch status</span><x-status-badge :status="$intakeBatch->derivedStatus()" /></div>
</div></div>

<div class="intake-progress-grid mb-4">
    @foreach ([
        App\DocumentIntakeStatus::Pending,
        App\DocumentIntakeStatus::Processing,
        App\DocumentIntakeStatus::NeedsVerification,
        App\DocumentIntakeStatus::Verified,
        App\DocumentIntakeStatus::Failed,
        App\DocumentIntakeStatus::Skipped,
    ] as $status)
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
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('invoice-intakes.documents.original', [$intakeBatch, $document]) }}">Open original</a>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('invoice-intakes.documents.show', [$intakeBatch, $document]) }}">View extraction</a>
            </div></td>
        </tr>
    @endforeach
    </tbody>
</table></div></div>
@endsection
