@extends('layouts.app')
@section('title', 'Add vendor')
@section('content')<h1 class="h2 mb-4">Add vendor</h1><div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="POST" action="{{ route('vendors.store') }}">@csrf @include('vendors._form', ['vendor' => null])</form></div></div>@endsection
