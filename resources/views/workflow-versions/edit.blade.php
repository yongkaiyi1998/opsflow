@extends('layouts.app')
@section('title', 'Workflow version '.$workflowVersion->version)
@section('content')
@php
    $editable = $workflowVersion->isDraft();
    $formatRuleValue = function ($rule) use ($departments, $categories) {
        $values = is_array($rule->value) ? $rule->value : [$rule->value];

        return collect($values)->map(function ($value) use ($rule, $departments, $categories) {
            return match ($rule->field) {
                App\WorkflowRuleField::Department => $departments->firstWhere('id', (int) $value)?->name ?? (string) $value,
                App\WorkflowRuleField::Category => $categories->firstWhere('id', (int) $value)?->name ?? (string) $value,
                default => (string) $value,
            };
        })->implode(', ');
    };
    $formatApprover = function ($step) use ($users) {
        return match ($step->approver_type) {
            App\ApproverType::Role => str((string) $step->approver_value)->lower()->title().' role',
            App\ApproverType::SpecificUser => $users->firstWhere('id', (int) $step->approver_value)?->name ?? 'User '.$step->approver_value,
            App\ApproverType::RequesterManager => 'Resolved from requester',
            App\ApproverType::DepartmentManager => 'Resolved from department',
        };
    };
@endphp

<div class="workflow-breadcrumb"><a href="{{ route('workflow-templates.index') }}">Workflows</a><span>/</span><a href="{{ route('workflow-templates.show', $workflowVersion->template) }}">{{ $workflowVersion->template->name }}</a><span>/</span><span>Version {{ $workflowVersion->version }}</span></div>
<x-page-header :title="'Version '.$workflowVersion->version" :description="$workflowVersion->template->module_type->label().' · '.$workflowVersion->template->name">
    <x-slot:actions>
        <x-status-badge :status="$workflowVersion->status" />
        @if ($editable)<form method="POST" action="{{ route('workflow-versions.publish', $workflowVersion) }}">@csrf<button class="btn btn-success" type="submit">Validate and publish</button></form>@endif
    </x-slot:actions>
</x-page-header>

@if ($editable)
    <div class="workflow-state-banner workflow-state-draft"><div><strong>Editable draft</strong><p>Changes here do not affect submissions until this version is validated and published.</p></div></div>
@else
    <div class="workflow-state-banner workflow-state-readonly"><div><strong>Immutable {{ str($workflowVersion->status->value)->lower() }} version</strong><p>Published and archived workflow versions are immutable. Clone the current published version to make changes.</p></div></div>
@endif

@if ($editable)
    <section class="card workflow-add-group"><div class="card-body">
        <div class="detail-section-heading"><div><h2>Add rule group</h2><p>Create a conditional policy block or the fallback route.</p></div></div>
        <form class="row g-3 align-items-end" method="POST" action="{{ route('workflow-rule-groups.store', $workflowVersion) }}">@csrf
            <div class="col-lg-5"><label class="form-label" for="new_group_name">Group name</label><input class="form-control" id="new_group_name" name="name" required maxlength="255"></div>
            <div class="col-sm-4 col-lg-2"><label class="form-label" for="new_group_priority">Priority</label><input class="form-control" id="new_group_priority" name="priority" type="number" min="1" value="10" required><div class="form-text">Lower runs first.</div></div>
            <div class="col-sm-4 col-lg-3"><input name="is_default" type="hidden" value="0"><div class="form-check workflow-default-check"><input class="form-check-input" id="new_group_default" name="is_default" type="checkbox" value="1"><label class="form-check-label" for="new_group_default">Default fallback route</label></div></div>
            <div class="col-sm-4 col-lg-2"><button class="btn btn-primary w-100" type="submit">Add group</button></div>
        </form>
    </div></section>
@endif

<div class="workflow-policy-list">
@forelse ($workflowVersion->ruleGroups as $group)
    <section class="card workflow-policy {{ $group->is_default ? 'workflow-policy-default' : '' }}">
        <div class="workflow-policy-header">
            <div class="workflow-policy-order"><span>Priority</span><strong>{{ $group->priority }}</strong></div>
            <div class="workflow-policy-title"><div class="d-flex flex-wrap align-items-center gap-2"><h2>{{ $group->name }}</h2>@if ($group->is_default)<span class="workflow-default-badge">Default fallback</span>@endif</div><p>{{ $group->is_default ? 'Used only when no conditional group matches.' : 'All conditions in this group must match.' }}</p></div>
            @if (! $editable)<x-status-badge :status="$workflowVersion->status" />@endif
        </div>

        @if ($editable)
            <form class="workflow-group-settings" method="POST" action="{{ route('workflow-rule-groups.update', $group) }}">@csrf @method('PUT')
                <div><label class="form-label" for="group_name_{{ $group->id }}">Group name</label><input class="form-control" id="group_name_{{ $group->id }}" name="name" value="{{ $group->name }}" required></div>
                <div><label class="form-label" for="group_priority_{{ $group->id }}">Priority</label><input class="form-control" id="group_priority_{{ $group->id }}" name="priority" type="number" min="1" value="{{ $group->priority }}" required></div>
                <div><input name="is_default" type="hidden" value="0"><div class="form-check workflow-default-check"><input class="form-check-input" id="group_default_{{ $group->id }}" name="is_default" type="checkbox" value="1" @checked($group->is_default)><label class="form-check-label" for="group_default_{{ $group->id }}">Default fallback</label></div></div>
                <button class="btn btn-sm btn-outline-primary" type="submit">Save group</button>
            </form>
        @endif

        <div class="workflow-policy-body">
            <section class="workflow-conditions" aria-labelledby="conditions-{{ $group->id }}">
                <div class="workflow-subsection-heading"><div><span class="workflow-section-index">1</span><div><h3 id="conditions-{{ $group->id }}">Conditions</h3><p>{{ $group->is_default ? 'No conditions are evaluated for the fallback.' : 'Evaluated together using AND.' }}</p></div></div></div>

                @if ($group->is_default)
                    <div class="workflow-default-message"><strong>Fallback route</strong><span>This group is considered only after every conditional group has failed to match.</span></div>
                @else
                    <div class="workflow-condition-list">
                        @forelse ($group->rules as $rule)
                            <div class="workflow-condition-card">
                                <div class="workflow-condition-summary"><span>{{ $rule->field->label() }}</span><strong>{{ $rule->operator->value }}</strong><span>{{ $formatRuleValue($rule) }}</span></div>
                                @if ($editable)
                                    <form class="workflow-condition-form" method="POST" action="{{ route('workflow-rules.update', $rule) }}">@csrf @method('PUT')
                                        <div><label class="form-label" for="field_{{ $rule->id }}">Field</label><select class="form-select" id="field_{{ $rule->id }}" name="field" required>@foreach (App\WorkflowRuleField::cases() as $field)<option value="{{ $field->value }}" @selected($rule->field === $field)>{{ $field->label() }}</option>@endforeach</select></div>
                                        <div><label class="form-label" for="operator_{{ $rule->id }}">Operator</label><select class="form-select" id="operator_{{ $rule->id }}" name="operator" required>@foreach (App\WorkflowRuleOperator::cases() as $operator)<option value="{{ $operator->value }}" @selected($rule->operator === $operator)>{{ $operator->value }}</option>@endforeach</select></div>
                                        <div><label class="form-label" for="value_{{ $rule->id }}">Value</label><input class="form-control" id="value_{{ $rule->id }}" name="value" value="{{ is_array($rule->value) ? implode(', ', $rule->value) : $rule->value }}" required></div>
                                        <div class="workflow-row-actions"><button class="btn btn-sm btn-outline-primary" type="submit">Save</button><button class="btn btn-sm btn-link text-danger" type="submit" form="delete_rule_{{ $rule->id }}">Delete</button></div>
                                    </form>
                                    <form id="delete_rule_{{ $rule->id }}" method="POST" action="{{ route('workflow-rules.destroy', $rule) }}">@csrf @method('DELETE')</form>
                                @endif
                            </div>
                        @empty
                            <x-empty-state title="No conditions configured" description="Add at least one condition before publishing this conditional group." />
                        @endforelse
                    </div>

                    @if ($editable)
                        <form class="workflow-add-row" method="POST" action="{{ route('workflow-rules.store', $group) }}">@csrf
                            <div><label class="form-label" for="new_field_{{ $group->id }}">Field</label><select class="form-select" id="new_field_{{ $group->id }}" name="field" required>@foreach (App\WorkflowRuleField::cases() as $field)<option value="{{ $field->value }}">{{ $field->label() }}</option>@endforeach</select></div>
                            <div><label class="form-label" for="new_operator_{{ $group->id }}">Operator</label><select class="form-select" id="new_operator_{{ $group->id }}" name="operator" required>@foreach (App\WorkflowRuleOperator::cases() as $operator)<option value="{{ $operator->value }}">{{ $operator->value }}</option>@endforeach</select></div>
                            <div><label class="form-label" for="new_value_{{ $group->id }}">Value</label><input class="form-control" id="new_value_{{ $group->id }}" name="value" required><div class="form-text">Decimal amount, one record ID, or comma-separated IDs for IN.</div></div>
                            <button class="btn btn-outline-primary" type="submit">Add condition</button>
                        </form>
                    @endif
                @endif
            </section>

            <section class="workflow-route" aria-labelledby="route-{{ $group->id }}">
                <div class="workflow-subsection-heading"><div><span class="workflow-section-index">2</span><div><h3 id="route-{{ $group->id }}">Approval route</h3><p>Steps run sequentially in ascending order.</p></div></div></div>
                <div class="workflow-route-list">
                    @forelse ($group->steps as $step)
                        <article class="workflow-route-step">
                            <div class="workflow-route-marker"><span>{{ $step->step_order }}</span></div>
                            <div class="workflow-route-content">
                                <div class="workflow-route-summary"><div><span>Step {{ $step->step_order }}</span><h4>{{ $step->name }}</h4></div><div class="workflow-route-approver"><strong>{{ $step->approver_type->label() }}</strong><small>{{ $formatApprover($step) }} · {{ $step->approval_mode->value }}@if ($step->sla_hours) · {{ $step->sla_hours }}h SLA @endif</small></div></div>
                                @if ($editable)
                                    <form class="workflow-step-form" method="POST" action="{{ route('workflow-steps.update', $step) }}">@csrf @method('PUT')
                                        <div><label class="form-label" for="order_{{ $step->id }}">Order</label><input class="form-control" id="order_{{ $step->id }}" name="step_order" type="number" min="1" value="{{ $step->step_order }}" required></div>
                                        <div><label class="form-label" for="step_name_{{ $step->id }}">Step name</label><input class="form-control" id="step_name_{{ $step->id }}" name="name" value="{{ $step->name }}" required></div>
                                        <div><label class="form-label" for="type_{{ $step->id }}">Approver type</label><select class="form-select" id="type_{{ $step->id }}" name="approver_type" required>@foreach (App\ApproverType::cases() as $type)<option value="{{ $type->value }}" @selected($step->approver_type === $type)>{{ $type->label() }}</option>@endforeach</select></div>
                                        <div><label class="form-label" for="approver_{{ $step->id }}">Role / user ID</label><input class="form-control" id="approver_{{ $step->id }}" name="approver_value" value="{{ $step->approver_value }}"></div>
                                        <input name="approval_mode" type="hidden" value="ANY">
                                        <div><label class="form-label" for="sla_{{ $step->id }}">SLA hours</label><input class="form-control" id="sla_{{ $step->id }}" name="sla_hours" type="number" min="1" value="{{ $step->sla_hours }}"></div>
                                        <div class="workflow-row-actions"><button class="btn btn-sm btn-outline-primary" type="submit">Save</button><button class="btn btn-sm btn-link text-danger" type="submit" form="delete_step_{{ $step->id }}">Delete</button></div>
                                    </form>
                                    <form id="delete_step_{{ $step->id }}" method="POST" action="{{ route('workflow-steps.destroy', $step) }}">@csrf @method('DELETE')</form>
                                @endif
                            </div>
                        </article>
                    @empty
                        <x-empty-state title="No approval steps configured" description="Add an approval step before this route can be published." />
                    @endforelse
                </div>

                @if ($editable)
                    <form class="workflow-add-row workflow-add-step" method="POST" action="{{ route('workflow-steps.store', $group) }}">@csrf
                        <div><label class="form-label" for="new_order_{{ $group->id }}">Order</label><input class="form-control" id="new_order_{{ $group->id }}" name="step_order" type="number" min="1" value="{{ $group->steps->count() + 1 }}" required></div>
                        <div><label class="form-label" for="new_step_name_{{ $group->id }}">Step name</label><input class="form-control" id="new_step_name_{{ $group->id }}" name="name" required></div>
                        <div><label class="form-label" for="new_type_{{ $group->id }}">Approver type</label><select class="form-select" id="new_type_{{ $group->id }}" name="approver_type" required>@foreach (App\ApproverType::cases() as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach</select></div>
                        <div><label class="form-label" for="new_approver_{{ $group->id }}">Role / user ID</label><input class="form-control" id="new_approver_{{ $group->id }}" name="approver_value"></div>
                        <input name="approval_mode" type="hidden" value="ANY">
                        <div><label class="form-label" for="new_sla_{{ $group->id }}">SLA hours</label><input class="form-control" id="new_sla_{{ $group->id }}" name="sla_hours" type="number" min="1"></div>
                        <button class="btn btn-outline-primary" type="submit">Add step</button>
                    </form>
                @endif
            </section>
        </div>

        @if ($editable)<div class="workflow-policy-footer"><form method="POST" action="{{ route('workflow-rule-groups.destroy', $group) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" type="submit">Delete rule group</button></form></div>@endif
    </section>
@empty
    <div class="card"><x-empty-state title="No rule groups configured" description="Add a rule group to begin building conditions and an approval route." /></div>
@endforelse
</div>

@if ($editable)
    <details class="workflow-reference"><summary>Configuration reference</summary><div class="workflow-reference-body"><p><strong>Role values:</strong> {{ implode(', ', array_column(App\UserRole::cases(), 'value')) }}</p><p><strong>Active users:</strong> {{ $users->map(fn ($user) => $user->id.' · '.$user->name)->implode(', ') ?: 'None' }}</p><p><strong>Departments:</strong> {{ $departments->map(fn ($department) => $department->id.' · '.$department->name)->implode(', ') ?: 'None' }}</p><p><strong>Spend categories:</strong> {{ $categories->map(fn ($category) => $category->id.' · '.$category->name)->implode(', ') ?: 'None' }}</p></div></details>
@endif
@endsection
