@extends('layouts.app')
@section('title', 'Upload invoice batch')
@section('content')
<x-page-header title="Upload invoice batch" description="Add up to 20 supplier invoice documents for secure, individual processing." />

<div class="form-layout form-layout-wide">
    <div class="card form-card">
        <div class="card-body">
            <form method="POST" action="{{ route('invoice-intakes.store') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="submission_key" value="{{ old('submission_key', $submissionKey) }}">

                <section class="form-section">
                    <div class="form-section-heading">
                        <h2>Invoice documents</h2>
                        <p>Each document becomes a private intake record. {{ config('ai.enabled') ? 'AI extraction is queued after upload.' : 'AI extraction is disabled, so uploaded documents remain pending until it is enabled.' }}</p>
                    </div>
                    <div>
                        <label class="form-label" for="documents">Files</label>
                        <input class="form-control @error('documents') is-invalid @enderror" id="documents" name="documents[]" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" multiple required>
                        <div class="form-text">PDF, JPEG, or PNG. Maximum 10 MB per file and 20 files per batch.</div>
                    </div>
                </section>

                <div class="form-actions">
                    <a class="btn btn-outline-secondary" href="{{ route('invoice-intakes.index') }}">Cancel</a>
                    <button class="btn btn-primary" type="submit">Upload batch</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
