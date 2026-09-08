@extends('layouts.app')
@section('title', 'Edit workflow')
@section('content')<h1 class="h2 mb-4">Edit workflow</h1><div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="POST" action="{{ route('workflow-templates.update', $workflowTemplate) }}">@csrf @method('PUT') @include('workflow-templates._form')</form></div></div>@endsection
