@extends('layouts.app')
@section('title', 'Add spend category')
@section('content')
<x-page-header title="Add spend category" description="Create a classification used by spend records and workflow routing." />
<div class="form-layout"><div class="card form-card"><div class="card-body"><form method="POST" action="{{ route('spend-categories.store') }}">@csrf @include('spend-categories._form', ['spendCategory' => null])</form></div></div></div>
@endsection
