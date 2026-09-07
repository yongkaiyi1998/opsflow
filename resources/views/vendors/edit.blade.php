@extends('layouts.app')
@section('title', 'Edit vendor')
@section('content')<h1 class="h2 mb-4">Edit vendor</h1><div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="POST" action="{{ route('vendors.update', $vendor) }}">@csrf @method('PUT') @include('vendors._form')</form></div></div>@endsection
