@extends('layouts.app')
@section('title', 'Edit '.$supplierInvoice->internal_no)
@section('content')
<x-page-header title="Edit supplier invoice" description="{{ $supplierInvoice->internal_no }} · Update the draft invoice details." />
<div class="form-layout form-layout-wide"><div class="card form-card"><div class="card-body"><form method="POST" action="{{ route('supplier-invoices.update', $supplierInvoice) }}">@csrf @method('PUT') @include('supplier-invoices._form')</form></div></div></div>
@endsection
