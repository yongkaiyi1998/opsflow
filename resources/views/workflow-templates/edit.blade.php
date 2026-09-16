@extends('layouts.app')
@section('title', 'Edit workflow')
@section('content')
<x-page-header title="Edit workflow" :description="$workflowTemplate->name.' · '.$workflowTemplate->code" />
<div class="form-layout"><div class="card form-card"><div class="card-body"><form method="POST" action="{{ route('workflow-templates.update', $workflowTemplate) }}">@csrf @method('PUT') @include('workflow-templates._form')</form></div></div></div>
@endsection
