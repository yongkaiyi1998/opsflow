@extends('layouts.app')
@section('title', 'Add vendor')
@section('content')
<x-page-header title="Add vendor" description="Create a supplier record for purchase requests and invoices." />
<div class="form-layout"><div class="card form-card"><div class="card-body"><form method="POST" action="{{ route('vendors.store') }}">@csrf @include('vendors._form', ['vendor' => null])</form></div></div></div>
@endsection
