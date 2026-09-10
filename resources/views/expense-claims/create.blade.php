@extends('layouts.app')
@section('title', 'New expense claim')
@section('content')
<div class="mb-4"><h1 class="h2 mb-1">New expense claim</h1><p class="text-secondary mb-0">Record reimbursable expenses and add receipts after saving.</p></div>
<div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="POST" action="{{ route('expense-claims.store') }}">@csrf @include('expense-claims._form', ['expenseClaim' => null])</form></div></div>
@endsection
