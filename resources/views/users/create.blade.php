@extends('layouts.app')
@section('title', 'Add user')
@section('content')
<x-page-header title="Add user" description="Create access and assign the user within the organization." />
<div class="form-layout"><div class="card form-card"><div class="card-body"><form method="POST" action="{{ route('users.store') }}">@csrf @include('users._form', ['user' => null])</form></div></div></div>
@endsection
