@extends('layouts.app')
@section('title', 'Edit '.$supplierInvoice->internal_no)
@section('content')
<h1 class="h2 mb-4">Edit {{ $supplierInvoice->internal_no }}</h1>
<div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="POST" action="{{ route('supplier-invoices.update', $supplierInvoice) }}">@csrf @method('PUT') @include('supplier-invoices._form')</form></div></div>
@endsection
