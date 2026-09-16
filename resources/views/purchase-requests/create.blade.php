@extends('layouts.app')
@section('title', 'New purchase request')
@section('content')
<x-page-header title="New purchase request" description="Describe the business need and add the items requiring approval." />
<div class="form-layout form-layout-wide"><div class="card form-card"><div class="card-body"><form method="POST" action="{{ route('purchase-requests.store') }}">@csrf @include('purchase-requests._form', ['purchaseRequest' => null])</form></div></div></div>
@endsection
