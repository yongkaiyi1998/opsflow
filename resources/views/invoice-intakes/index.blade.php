@extends('layouts.app')
@section('title', 'Invoice intake')
@section('content')
<x-page-header title="Invoice intake" description="Upload supplier invoice documents for secure, individual processing.">
    <x-slot:actions><a class="btn btn-primary" href="{{ route('invoice-intakes.create') }}">Upload batch</a></x-slot:actions>
</x-page-header>

<div class="card table-card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Batch</th><th>Uploaded by</th><th>Uploaded</th><th>Documents</th><th>Progress</th><th>Status</th><th class="text-end">Action</th></tr></thead>
    <tbody>
    @forelse ($intakeBatches as $batch)
        @php($counts = $batch->statusCounts())
        <tr>
            <td class="fw-semibold text-nowrap">Batch #{{ str_pad((string) $batch->id, 6, '0', STR_PAD_LEFT) }}</td>
            <td>{{ $batch->uploadedBy->name }}</td>
            <td class="text-nowrap"><span class="d-block">{{ $batch->created_at->format('j M Y') }}</span><small class="text-secondary">{{ $batch->created_at->format('g:i A') }}</small></td>
            <td>{{ $batch->documentIntakes->count() }}</td>
            <td><span class="d-block fw-medium">{{ $counts[App\DocumentIntakeStatus::Verified->value] + $counts[App\DocumentIntakeStatus::Skipped->value] }} complete</span><small class="text-secondary">{{ $counts[App\DocumentIntakeStatus::NeedsVerification->value] }} need verification · {{ $counts[App\DocumentIntakeStatus::Failed->value] }} failed</small></td>
            <td><x-status-badge :status="$batch->derivedStatus()" /></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('invoice-intakes.show', $batch) }}">Review batch</a></td>
        </tr>
    @empty
        <tr><td colspan="7"><x-empty-state title="No invoice intake batches yet" description="Upload a batch of PDF or image invoices when they are ready for processing.">
            <x-slot:action><a class="btn btn-primary btn-sm" href="{{ route('invoice-intakes.create') }}">Upload first batch</a></x-slot:action>
        </x-empty-state></td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $intakeBatches->links() }}</div>
@endsection
