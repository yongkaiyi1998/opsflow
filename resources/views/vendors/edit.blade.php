@extends('layouts.app')
@section('title', 'Edit vendor')
@section('content')
<x-page-header title="Edit vendor" description="Update supplier contact details and lifecycle status." />
<div class="form-layout"><div class="card form-card"><div class="card-body"><form method="POST" action="{{ route('vendors.update', $vendor) }}">@csrf @method('PUT') @include('vendors._form')</form></div></div></div>
@endsection
