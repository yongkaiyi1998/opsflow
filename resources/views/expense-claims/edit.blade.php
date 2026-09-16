@extends('layouts.app')
@section('title', 'Edit '.$expenseClaim->claim_no)
@section('content')
<x-page-header title="Edit expense claim" description="{{ $expenseClaim->claim_no }} · Update the claim and expense items." />
<div class="form-layout form-layout-wide"><div class="card form-card"><div class="card-body"><form method="POST" action="{{ route('expense-claims.update', $expenseClaim) }}">@csrf @method('PUT') @include('expense-claims._form')</form></div></div></div>
@endsection
