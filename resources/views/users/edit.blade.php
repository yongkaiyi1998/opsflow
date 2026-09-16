@extends('layouts.app')
@section('title', 'Edit user')
@section('content')
<x-page-header title="Edit user" description="Update account access and organization assignments." />
<div class="form-layout"><div class="card form-card"><div class="card-body"><form method="POST" action="{{ route('users.update', $user) }}">@csrf @method('PUT') @include('users._form')</form></div></div></div>
@endsection
