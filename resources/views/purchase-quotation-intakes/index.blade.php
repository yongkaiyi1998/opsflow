@extends('layouts.app')
@section('title', 'Quotation intake')
@section('content')
<x-page-header title="Quotation intake" description="Upload supplier quotations and review AI-extracted candidate data before creating a Purchase Request.">
    <x-slot:actions><a class="btn btn-primary" href="{{ route('purchase-quotation-intakes.create') }}">Upload quotations</a></x-slot:actions>
</x-page-header>
<div class="card table-card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Batch</th><th>Uploaded</th><th>Quotations</th><th>Progress</th><th>Status</th><th class="text-end">Action</th></tr></thead>
    <tbody>
    @forelse ($batches as $batch)
        @php($counts = $batch->statusCounts())
        <tr>
            <td class="fw-semibold text-nowrap">Batch #{{ str_pad((string) $batch->id, 6, '0', STR_PAD_LEFT) }}</td>
            <td class="text-nowrap"><span class="d-block">{{ $batch->created_at->format('j M Y') }}</span><small class="text-secondary">{{ $batch->created_at->format('g:i A') }}</small></td>
            <td>{{ $batch->documentIntakes->count() }}</td>
            <td><span class="d-block fw-medium">{{ $counts[App\DocumentIntakeStatus::Verified->value] + $counts[App\DocumentIntakeStatus::Skipped->value] }} complete</span><small class="text-secondary">{{ $counts[App\DocumentIntakeStatus::NeedsVerification->value] }} need verification · {{ $counts[App\DocumentIntakeStatus::Failed->value] }} failed</small></td>
            <td><x-status-badge :status="$batch->derivedStatus()" /></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-quotation-intakes.show', $batch) }}">Review batch</a></td>
        </tr>
    @empty
        <tr><td colspan="6"><x-empty-state title="No quotation intake batches yet" description="Upload a batch of PDF or image quotations when they are ready for processing.">
            <x-slot:action><a class="btn btn-primary btn-sm" href="{{ route('purchase-quotation-intakes.create') }}">Upload first batch</a></x-slot:action>
        </x-empty-state></td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $batches->links() }}</div>
@endsection
