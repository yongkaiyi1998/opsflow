@extends('layouts.app')
@section('title', 'New supplier invoice')
@section('content')
<x-page-header title="New supplier invoice" description="Record supplier, invoice and line-item details for approval." />
<div class="form-layout form-layout-wide"><div class="card form-card"><div class="card-body"><form method="POST" action="{{ route('supplier-invoices.store') }}">@csrf @include('supplier-invoices._form', ['supplierInvoice' => null])</form></div></div></div>
@endsection
