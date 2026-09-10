@extends('layouts.app')
@section('title', 'Edit '.$expenseClaim->claim_no)
@section('content')
<div class="mb-4"><h1 class="h2 mb-1">Edit expense claim</h1><p class="text-secondary mb-0">{{ $expenseClaim->claim_no }}</p></div>
<div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="POST" action="{{ route('expense-claims.update', $expenseClaim) }}">@csrf @method('PUT') @include('expense-claims._form')</form></div></div>
@endsection
