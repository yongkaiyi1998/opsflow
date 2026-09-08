<?php

namespace App\Http\Requests;

use App\ApprovalMode;
use App\ApproverType;
use App\Models\User;
use App\UserRole;
use App\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveWorkflowStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->route('workflow_step') ?? $this->route('workflow_rule_group');

        return $this->user()?->can('update', $subject) ?? false;
    }

    public function rules(): array
    {
        return [
            'step_order' => ['required', 'integer', 'min:1', 'max:999999'],
            'name' => ['required', 'string', 'max:255'],
            'approver_type' => ['required', Rule::enum(ApproverType::class)],
            'approver_value' => ['nullable', 'string', 'max:255'],
            'approval_mode' => ['required', Rule::in([ApprovalMode::Any->value])],
            'sla_hours' => ['nullable', 'integer', 'min:1', 'max:999999'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $type = ApproverType::tryFrom($this->string('approver_type')->toString());
            $value = $this->string('approver_value')->toString();

            if ($type === ApproverType::Role && UserRole::tryFrom($value) === null) {
                $validator->errors()->add('approver_value', 'Select a valid role.');
            }

            if ($type === ApproverType::SpecificUser
                && (! ctype_digit($value) || ! User::whereKey((int) $value)->where('status', UserStatus::Active->value)->exists())) {
                $validator->errors()->add('approver_value', 'Select an active user.');
            }
        }];
    }

    /** @return array{step_order: int, name: string, approver_type: string, approver_value: ?string, approval_mode: string, minimum_approvals: null, sla_hours: ?int} */
    public function configuration(): array
    {
        $type = ApproverType::from($this->validated('approver_type'));

        return [
            'step_order' => (int) $this->validated('step_order'),
            'name' => $this->validated('name'),
            'approver_type' => $type->value,
            'approver_value' => in_array($type, [ApproverType::Role, ApproverType::SpecificUser], true) ? $this->validated('approver_value') : null,
            'approval_mode' => ApprovalMode::Any->value,
            'minimum_approvals' => null,
            'sla_hours' => $this->validated('sla_hours') === null ? null : (int) $this->validated('sla_hours'),
        ];
    }
}
