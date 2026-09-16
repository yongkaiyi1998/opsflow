@extends('layouts.app')
@section('title', 'Edit spend category')
@section('content')
<x-page-header title="Edit spend category" description="Update the category label, code or lifecycle status." />
<div class="form-layout"><div class="card form-card"><div class="card-body"><form method="POST" action="{{ route('spend-categories.update', $spendCategory) }}">@csrf @method('PUT') @include('spend-categories._form')</form></div></div></div>
@endsection
