@extends('layouts.app')
@section('title', 'New expense claim')
@section('content')
<x-page-header title="New expense claim" description="Record reimbursable expenses and add private receipts after saving." />
<div class="form-layout form-layout-wide"><div class="card form-card"><div class="card-body"><form method="POST" action="{{ route('expense-claims.store') }}">@csrf @include('expense-claims._form', ['expenseClaim' => null])</form></div></div></div>
@endsection
