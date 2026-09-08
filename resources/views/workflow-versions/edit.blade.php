@extends('layouts.app')
@section('title', 'Workflow version '.$workflowVersion->version)
@section('content')
@php($editable = $workflowVersion->isDraft())
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div><a class="small text-decoration-none" href="{{ route('workflow-templates.show', $workflowVersion->template) }}">← {{ $workflowVersion->template->name }}</a><h1 class="h2 mt-2 mb-1">Version {{ $workflowVersion->version }}</h1><p class="text-secondary mb-0">{{ $editable ? 'Draft configuration' : 'Read-only '.$workflowVersion->status->value.' configuration' }}</p></div>
    @if ($editable)<form method="POST" action="{{ route('workflow-versions.publish', $workflowVersion) }}">@csrf<button class="btn btn-success" type="submit">Validate and publish</button></form>@endif
</div>
@unless ($editable)<div class="alert alert-info">Published and archived workflow versions are immutable. Clone the current published version to make changes.</div>@endunless

@if ($editable)
<div class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5">Add rule group</h2><form class="row g-3 align-items-end" method="POST" action="{{ route('workflow-rule-groups.store', $workflowVersion) }}">@csrf
    <div class="col-md-6"><label class="form-label" for="new_group_name">Name</label><input class="form-control" id="new_group_name" name="name" required maxlength="255"></div>
    <div class="col-md-3"><label class="form-label" for="new_group_priority">Priority</label><input class="form-control" id="new_group_priority" name="priority" type="number" min="1" value="10" required><div class="form-text">Lower numbers run first.</div></div>
    <div class="col-md-2"><input name="is_default" type="hidden" value="0"><div class="form-check mb-2"><input class="form-check-input" id="new_group_default" name="is_default" type="checkbox" value="1"><label class="form-check-label" for="new_group_default">Default route</label></div></div>
    <div class="col-md-1"><button class="btn btn-primary" type="submit">Add</button></div>
</form></div></div>
@endif

@forelse ($workflowVersion->ruleGroups as $group)
<section class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        @if ($editable)
        <form class="row g-2 align-items-center" method="POST" action="{{ route('workflow-rule-groups.update', $group) }}">@csrf @method('PUT')
            <div class="col-md-5"><label class="visually-hidden" for="group_name_{{ $group->id }}">Group name</label><input class="form-control fw-semibold" id="group_name_{{ $group->id }}" name="name" value="{{ $group->name }}" required></div>
            <div class="col-md-2"><label class="visually-hidden" for="group_priority_{{ $group->id }}">Priority</label><input class="form-control" id="group_priority_{{ $group->id }}" name="priority" type="number" min="1" value="{{ $group->priority }}" required></div>
            <div class="col-md-2"><input name="is_default" type="hidden" value="0"><div class="form-check"><input class="form-check-input" id="group_default_{{ $group->id }}" name="is_default" type="checkbox" value="1" @checked($group->is_default)><label class="form-check-label" for="group_default_{{ $group->id }}">Default</label></div></div>
            <div class="col-md-3 text-end"><button class="btn btn-sm btn-outline-primary" type="submit">Save group</button></div>
        </form>
        @else<div class="d-flex justify-content-between"><h2 class="h5 mb-0">{{ $group->name }}</h2><span class="text-secondary">Priority {{ $group->priority }}{{ $group->is_default ? ' · Default' : '' }}</span></div>@endif
    </div>
    <div class="card-body">
        <h3 class="h6">Rules <span class="text-secondary fw-normal">(all must match)</span></h3>
        @if ($group->is_default)<p class="text-secondary">This default route is used when no specific group matches.</p>
        @else
            @forelse ($group->rules as $rule)
            <form class="row g-2 align-items-end border rounded p-2 mb-2" method="POST" action="{{ route('workflow-rules.update', $rule) }}">@csrf @method('PUT')
                <div class="col-md-3"><label class="form-label small" for="field_{{ $rule->id }}">Field</label><select class="form-select" id="field_{{ $rule->id }}" name="field" required @disabled(! $editable)>@foreach (App\WorkflowRuleField::cases() as $field)<option value="{{ $field->value }}" @selected($rule->field === $field)>{{ $field->label() }}</option>@endforeach</select></div>
                <div class="col-md-2"><label class="form-label small" for="operator_{{ $rule->id }}">Operator</label><select class="form-select" id="operator_{{ $rule->id }}" name="operator" required @disabled(! $editable)>@foreach (App\WorkflowRuleOperator::cases() as $operator)<option value="{{ $operator->value }}" @selected($rule->operator === $operator)>{{ $operator->value }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label small" for="value_{{ $rule->id }}">Value</label><input class="form-control" id="value_{{ $rule->id }}" name="value" value="{{ is_array($rule->value) ? implode(', ', $rule->value) : $rule->value }}" required @disabled(! $editable)></div>
                @if ($editable)<div class="col-md-3 text-end"><button class="btn btn-sm btn-outline-primary" type="submit">Save</button><button class="btn btn-sm btn-outline-danger" type="submit" form="delete_rule_{{ $rule->id }}">Delete</button></div>@endif
            </form>
            @if ($editable)<form id="delete_rule_{{ $rule->id }}" method="POST" action="{{ route('workflow-rules.destroy', $rule) }}">@csrf @method('DELETE')</form>@endif
            @empty<p class="text-danger">No rules configured.</p>@endforelse
            @if ($editable)
            <form class="row g-2 align-items-end mt-2" method="POST" action="{{ route('workflow-rules.store', $group) }}">@csrf
                <div class="col-md-3"><label class="form-label" for="new_field_{{ $group->id }}">Field</label><select class="form-select" id="new_field_{{ $group->id }}" name="field" required>@foreach (App\WorkflowRuleField::cases() as $field)<option value="{{ $field->value }}">{{ $field->label() }}</option>@endforeach</select></div>
                <div class="col-md-2"><label class="form-label" for="new_operator_{{ $group->id }}">Operator</label><select class="form-select" id="new_operator_{{ $group->id }}" name="operator" required>@foreach (App\WorkflowRuleOperator::cases() as $operator)<option value="{{ $operator->value }}">{{ $operator->value }}</option>@endforeach</select></div>
                <div class="col-md-5"><label class="form-label" for="new_value_{{ $group->id }}">Value</label><input class="form-control" id="new_value_{{ $group->id }}" name="value" required><div class="form-text">Use a decimal amount, one record ID, or comma-separated IDs for IN.</div></div>
                <div class="col-md-2"><button class="btn btn-outline-primary" type="submit">Add rule</button></div>
            </form>
            @endif
        @endif

        <hr class="my-4"><h3 class="h6">Approval steps</h3>
        @forelse ($group->steps as $step)
        <form class="row g-2 align-items-end border rounded p-2 mb-2" method="POST" action="{{ route('workflow-steps.update', $step) }}">@csrf @method('PUT')
            <div class="col-md-1"><label class="form-label small" for="order_{{ $step->id }}">Order</label><input class="form-control" id="order_{{ $step->id }}" name="step_order" type="number" min="1" value="{{ $step->step_order }}" required @disabled(! $editable)></div>
            <div class="col-md-3"><label class="form-label small" for="step_name_{{ $step->id }}">Name</label><input class="form-control" id="step_name_{{ $step->id }}" name="name" value="{{ $step->name }}" required @disabled(! $editable)></div>
            <div class="col-md-3"><label class="form-label small" for="type_{{ $step->id }}">Approver</label><select class="form-select" id="type_{{ $step->id }}" name="approver_type" required @disabled(! $editable)>@foreach (App\ApproverType::cases() as $type)<option value="{{ $type->value }}" @selected($step->approver_type === $type)>{{ $type->label() }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label small" for="approver_{{ $step->id }}">Role / user ID</label><input class="form-control" id="approver_{{ $step->id }}" name="approver_value" value="{{ $step->approver_value }}" @disabled(! $editable)></div>
            <input name="approval_mode" type="hidden" value="ANY"><div class="col-md-1"><label class="form-label small" for="sla_{{ $step->id }}">SLA hours</label><input class="form-control" id="sla_{{ $step->id }}" name="sla_hours" type="number" min="1" value="{{ $step->sla_hours }}" @disabled(! $editable)></div>
            @if ($editable)<div class="col-md-2 text-end"><button class="btn btn-sm btn-outline-primary" type="submit">Save</button><button class="btn btn-sm btn-outline-danger" type="submit" form="delete_step_{{ $step->id }}">Delete</button></div>@endif
        </form>
        @if ($editable)<form id="delete_step_{{ $step->id }}" method="POST" action="{{ route('workflow-steps.destroy', $step) }}">@csrf @method('DELETE')</form>@endif
        @empty<p class="text-danger">No approval steps configured.</p>@endforelse

        @if ($editable)
        <form class="row g-2 align-items-end mt-2" method="POST" action="{{ route('workflow-steps.store', $group) }}">@csrf
            <div class="col-md-1"><label class="form-label" for="new_order_{{ $group->id }}">Order</label><input class="form-control" id="new_order_{{ $group->id }}" name="step_order" type="number" min="1" value="{{ $group->steps->count() + 1 }}" required></div>
            <div class="col-md-3"><label class="form-label" for="new_step_name_{{ $group->id }}">Name</label><input class="form-control" id="new_step_name_{{ $group->id }}" name="name" required></div>
            <div class="col-md-3"><label class="form-label" for="new_type_{{ $group->id }}">Approver</label><select class="form-select" id="new_type_{{ $group->id }}" name="approver_type" required>@foreach (App\ApproverType::cases() as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label" for="new_approver_{{ $group->id }}">Role / user ID</label><input class="form-control" id="new_approver_{{ $group->id }}" name="approver_value"></div>
            <input name="approval_mode" type="hidden" value="ANY"><div class="col-md-1"><label class="form-label" for="new_sla_{{ $group->id }}">SLA</label><input class="form-control" id="new_sla_{{ $group->id }}" name="sla_hours" type="number" min="1"></div>
            <div class="col-md-2"><button class="btn btn-outline-primary" type="submit">Add step</button></div>
        </form>
        @endif

        @if ($editable)<div class="d-flex justify-content-end mt-4"><form method="POST" action="{{ route('workflow-rule-groups.destroy', $group) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" type="submit">Delete rule group</button></form></div>@endif
    </div>
</section>
@empty<div class="alert alert-warning">Add a rule group to begin configuring this version.</div>@endforelse

@if ($editable)
<div class="card border-0 bg-light"><div class="card-body"><h2 class="h6">Configuration reference</h2><p class="small mb-1"><strong>Role values:</strong> {{ implode(', ', array_column(App\UserRole::cases(), 'value')) }}</p><p class="small mb-1"><strong>Active user IDs:</strong> {{ $users->map(fn ($user) => $user->id.' '.$user->name)->implode(', ') ?: 'None' }}</p><p class="small mb-1"><strong>Department IDs:</strong> {{ $departments->map(fn ($department) => $department->id.' '.$department->name)->implode(', ') ?: 'None' }}</p><p class="small mb-0"><strong>Category IDs:</strong> {{ $categories->map(fn ($category) => $category->id.' '.$category->name)->implode(', ') ?: 'None' }}</p></div></div>
@endif
@endsection
