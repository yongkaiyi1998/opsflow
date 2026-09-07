@extends('layouts.app')
@section('title', 'Add spend category')
@section('content')<h1 class="h2 mb-4">Add spend category</h1><div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="POST" action="{{ route('spend-categories.store') }}">@csrf @include('spend-categories._form', ['spendCategory' => null])</form></div></div>@endsection
