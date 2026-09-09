@extends('layouts.app')
@section('title', 'New supplier invoice')
@section('content')
<h1 class="h2 mb-4">New supplier invoice</h1>
<div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="POST" action="{{ route('supplier-invoices.store') }}">@csrf @include('supplier-invoices._form', ['supplierInvoice' => null])</form></div></div>
@endsection
