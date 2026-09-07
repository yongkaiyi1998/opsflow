@extends('layouts.app')
@section('title', 'Add user')
@section('content')<h1 class="h2 mb-4">Add user</h1><div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="POST" action="{{ route('users.store') }}">@csrf @include('users._form', ['user' => null])</form></div></div>@endsection
