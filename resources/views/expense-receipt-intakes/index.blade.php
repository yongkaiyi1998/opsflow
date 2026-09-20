@extends('layouts.app')
@section('title', 'Receipt intake')
@section('content')
<x-page-header title="Receipt intake" description="Upload expense receipts and review extracted candidate details before creating a claim.">
    <x-slot:actions>
        <a class="btn btn-primary" href="{{ route('expense-receipt-intakes.create') }}">Upload receipts</a>
    </x-slot:actions>
</x-page-header>

@if ($intakeBatches->isEmpty())
    <x-empty-state title="No receipt batches yet" description="Upload one or more receipts to begin extracting expense details.">
        <a class="btn btn-primary" href="{{ route('expense-receipt-intakes.create') }}">Upload receipts</a>
    </x-empty-state>
@else
    <div class="card table-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Batch</th><th>Uploaded</th><th>Receipts</th><th>Progress</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                @foreach ($intakeBatches as $batch)
                    @php($counts = $batch->statusCounts())
                    <tr>
                        <td class="fw-semibold">#{{ str_pad((string) $batch->id, 6, '0', STR_PAD_LEFT) }}</td>
                        <td class="text-nowrap">{{ $batch->created_at->format('j M Y, g:i A') }}</td>
                        <td>{{ $batch->documentIntakes->count() }}</td>
                        <td><span class="d-block fw-medium">{{ $counts[App\DocumentIntakeStatus::NeedsVerification->value] }} need verification</span><small class="text-secondary">{{ $counts[App\DocumentIntakeStatus::Pending->value] }} pending · {{ $counts[App\DocumentIntakeStatus::Failed->value] }} failed</small></td>
                        <td><x-status-badge :status="$batch->derivedStatus()" /></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('expense-receipt-intakes.show', $batch) }}">Open batch</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $intakeBatches->links() }}</div>
@endif
@endsection
