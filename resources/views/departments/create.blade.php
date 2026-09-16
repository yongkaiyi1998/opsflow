@extends('layouts.app')
@section('title', 'Add department')
@section('content')
<x-page-header title="Add department" description="Create an organization unit and optionally assign its approval manager." />
<div class="form-layout"><div class="card form-card"><div class="card-body"><form method="POST" action="{{ route('departments.store') }}">@csrf @include('departments._form', ['department' => null])</form></div></div></div>
@endsection
