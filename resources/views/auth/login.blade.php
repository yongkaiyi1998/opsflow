@extends('layouts.app')
@section('title', 'Sign in')
@section('content')
<form method="POST" action="{{ route('login') }}">
    @csrf
    <div class="mb-3">
        <label for="email" class="form-label">Email address</label>
        <input id="email" name="email" type="email" class="form-control" value="{{ old('email') }}" autocomplete="username" required autofocus>
    </div>
    <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <input id="password" name="password" type="password" class="form-control" autocomplete="current-password" required>
    </div>
    <div class="form-check mb-4">
        <input id="remember" name="remember" type="checkbox" value="1" class="form-check-input" @checked(old('remember'))>
        <label for="remember" class="form-check-label">Remember me</label>
    </div>
    <button type="submit" class="btn btn-primary w-100">Sign in</button>
    <a class="d-block mt-3 text-center" href="{{ route('password.request') }}">Forgot your password?</a>
</form>
@endsection
