@extends('layouts.app')
@section('title', 'Add department')
@section('content')
<h1 class="h2 mb-4">Add department</h1>
<div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="POST" action="{{ route('departments.store') }}">@csrf @include('departments._form', ['department' => null])</form></div></div>
@endsection
