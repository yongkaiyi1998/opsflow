@extends('layouts.app')
@section('title', 'Edit department')
@section('content')
<x-page-header title="Edit department" description="Update organization ownership and lifecycle status." />
<div class="form-layout"><div class="card form-card"><div class="card-body"><form method="POST" action="{{ route('departments.update', $department) }}">@csrf @method('PUT') @include('departments._form')</form></div></div></div>
@endsection
