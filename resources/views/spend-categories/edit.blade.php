@extends('layouts.app')
@section('title', 'Edit spend category')
@section('content')<h1 class="h2 mb-4">Edit spend category</h1><div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="POST" action="{{ route('spend-categories.update', $spendCategory) }}">@csrf @method('PUT') @include('spend-categories._form')</form></div></div>@endsection
