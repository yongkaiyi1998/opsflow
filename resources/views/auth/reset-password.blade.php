@extends('layouts.app')
@section('title', 'Reset password')
@section('content')
<form method="POST" action="{{ route('password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <div class="mb-3">
        <label for="email" class="form-label">Email address</label>
        <input id="email" name="email" type="email" class="form-control" value="{{ old('email', $email) }}" autocomplete="username" required>
    </div>
    <div class="mb-3">
        <label for="password" class="form-label">New password</label>
        <input id="password" name="password" type="password" class="form-control" autocomplete="new-password" minlength="8" required>
    </div>
    <div class="mb-4">
        <label for="password_confirmation" class="form-label">Confirm password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" class="form-control" autocomplete="new-password" minlength="8" required>
    </div>
    <button type="submit" class="btn btn-primary w-100">Reset password</button>
    <a class="d-block mt-3 text-center" href="{{ route('login') }}">Back to sign in</a>
</form>
@endsection
