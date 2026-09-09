@extends('layouts.app')
@section('title', 'New purchase request')
@section('content')
<h1 class="h2 mb-4">New purchase request</h1>
<div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="POST" action="{{ route('purchase-requests.store') }}">@csrf @include('purchase-requests._form', ['purchaseRequest' => null])</form></div></div>
@endsection
