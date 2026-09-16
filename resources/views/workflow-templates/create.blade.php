@extends('layouts.app')
@section('title', 'Add workflow')
@section('content')
<x-page-header title="Add workflow" description="Create a versioned approval policy for a spend module." />
<div class="form-layout"><div class="card form-card"><div class="card-body"><form method="POST" action="{{ route('workflow-templates.store') }}">@csrf @include('workflow-templates._form', ['workflowTemplate' => null])</form></div></div></div>
@endsection
