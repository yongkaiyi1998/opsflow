@extends('layouts.app')
@section('title', 'Upload receipts')
@section('content')
<div class="workflow-breadcrumb"><a href="{{ route('expense-receipt-intakes.index') }}">Receipt intake</a><span>/</span><span>Upload</span></div>
<x-page-header title="Upload expense receipts" description="Upload receipts now; each verified receipt will later become an Expense Claim item." />

<div class="row justify-content-center">
    <div class="col-xl-8">
        <div class="card form-card"><div class="card-body">
            <form method="POST" action="{{ route('expense-receipt-intakes.store') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="submission_key" value="{{ old('submission_key', $submissionKey) }}">
                <div class="mb-4">
                    <label class="form-label" for="documents">Receipt files</label>
                    <input class="form-control @error('documents') is-invalid @enderror" id="documents" name="documents[]" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" multiple required>
                    @error('documents')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    @error('documents.*')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                    <div class="form-text">PDF, JPEG, or PNG. Up to {{ config('document_intake.max_files') }} receipts, {{ config('document_intake.max_size') }} each.</div>
                </div>
                @if (config('ai.enabled'))
                    <div class="alert alert-info">Extraction is queued after upload. Candidate values remain read-only until the RI02 verification step is available.</div>
                @else
                    <div class="alert alert-info">AI extraction is disabled. Your private receipts will remain pending and can be processed later.</div>
                @endif
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">Upload receipts</button>
                    <a class="btn btn-outline-secondary" href="{{ route('expense-receipt-intakes.index') }}">Cancel</a>
                </div>
            </form>
        </div></div>
    </div>
</div>
@endsection
