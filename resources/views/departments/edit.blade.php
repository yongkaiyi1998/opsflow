@extends('layouts.app')
@section('title', 'Edit department')
@section('content')
<h1 class="h2 mb-4">Edit department</h1>
<div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="POST" action="{{ route('departments.update', $department) }}">@csrf @method('PUT') @include('departments._form')</form></div></div>
@endsection
