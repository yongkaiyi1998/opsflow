@extends('layouts.app')
@section('title', 'Forgot password')
@section('content')
<p class="text-secondary">Enter your account email to request a password reset link.</p>
<form method="POST" action="{{ route('password.email') }}">
    @csrf
    <div class="mb-4">
        <label for="email" class="form-label">Email address</label>
        <input id="email" name="email" type="email" class="form-control" value="{{ old('email') }}" autocomplete="username" required autofocus>
    </div>
    <button type="submit" class="btn btn-primary w-100">Send reset link</button>
    <a class="d-block mt-3 text-center" href="{{ route('login') }}">Back to sign in</a>
</form>
@endsection
