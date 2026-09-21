@extends('layouts.app')
@section('title', 'Upload quotations')
@section('content')
<div class="workflow-breadcrumb"><a href="{{ route('purchase-quotation-intakes.index') }}">Quotation intake</a><span>/</span><span>Upload</span></div>
<x-page-header title="Upload purchase quotations" description="AI extracts candidate quotation data for later human review. No Purchase Request is created at this stage." />
<div class="row justify-content-center"><div class="col-xl-8"><div class="card form-card"><div class="card-body">
    <form method="POST" action="{{ route('purchase-quotation-intakes.store') }}" enctype="multipart/form-data">@csrf
        <input type="hidden" name="submission_key" value="{{ old('submission_key', $submissionKey) }}">
        <div class="mb-4"><label class="form-label" for="documents">Quotation files</label><input class="form-control @error('documents') is-invalid @enderror" id="documents" name="documents[]" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" multiple required>@error('documents')<div class="invalid-feedback">{{ $message }}</div>@enderror @error('documents.*')<div class="text-danger small mt-2">{{ $message }}</div>@enderror<div class="form-text">PDF, JPEG, or PNG. Up to {{ config('document_intake.max_files') }} quotations, {{ config('document_intake.max_size') }} each.</div></div>
        <div class="alert alert-info">{{ config('ai.enabled') ? 'Extraction is queued after upload. All values remain candidate data until human verification in QI02.' : 'AI extraction is disabled. Private quotations remain pending and can be processed later.' }}</div>
        <div class="d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit">Upload quotations</button><a class="btn btn-outline-secondary" href="{{ route('purchase-quotation-intakes.index') }}">Cancel</a></div>
    </form>
</div></div></div></div>
@endsection
