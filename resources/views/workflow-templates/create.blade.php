@extends('layouts.app')
@section('title', 'Add workflow')
@section('content')<h1 class="h2 mb-4">Add workflow</h1><div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="POST" action="{{ route('workflow-templates.store') }}">@csrf @include('workflow-templates._form', ['workflowTemplate' => null])</form></div></div>@endsection
