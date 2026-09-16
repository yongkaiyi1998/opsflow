@extends('layouts.app')
@section('title', 'Edit '.$purchaseRequest->request_no)
@section('content')
<x-page-header title="Edit purchase request" description="{{ $purchaseRequest->request_no }} · Update the draft before submission." />
<div class="form-layout form-layout-wide"><div class="card form-card"><div class="card-body"><form method="POST" action="{{ route('purchase-requests.update', $purchaseRequest) }}">@csrf @method('PUT') @include('purchase-requests._form')</form></div></div></div>
@endsection
