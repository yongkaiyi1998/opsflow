@extends('layouts.app')
@section('title', 'Edit '.$purchaseRequest->request_no)
@section('content')
<h1 class="h2 mb-4">Edit {{ $purchaseRequest->request_no }}</h1>
<div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="POST" action="{{ route('purchase-requests.update', $purchaseRequest) }}">@csrf @method('PUT') @include('purchase-requests._form')</form></div></div>
@endsection
