@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif
@if (session('success'))
    <div class="alert alert-success" role="status">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <p class="fw-semibold mb-1">Please check the following:</p>
        <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif
