@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<h1 class="h2">Welcome, {{ auth()->user()->name }}</h1>
<p class="text-secondary">Your OpsFlow workspace</p>
<section class="card border-0 shadow-sm mt-4" aria-labelledby="account-heading">
    <div class="card-body p-4">
        <h2 id="account-heading" class="h5">Your account</h2>
        <dl class="row mb-0 mt-3">
            <dt class="col-sm-3">Email</dt><dd class="col-sm-9">{{ auth()->user()->email }}</dd>
            <dt class="col-sm-3">Role</dt><dd class="col-sm-9">{{ ucfirst(strtolower(auth()->user()->role->value)) }}</dd>
            <dt class="col-sm-3">Status</dt><dd class="col-sm-9"><span class="badge text-bg-success">Active</span></dd>
        </dl>
    </div>
</section>
@endsection
