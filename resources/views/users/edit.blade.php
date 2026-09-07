@extends('layouts.app')
@section('title', 'Edit user')
@section('content')<h1 class="h2 mb-4">Edit user</h1><div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="POST" action="{{ route('users.update', $user) }}">@csrf @method('PUT') @include('users._form')</form></div></div>@endsection
