<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveWorkflowRuleGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->route('workflow_rule_group') ?? $this->route('workflow_version');

        return $this->user()?->can('update', $subject) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'priority' => ['required', 'integer', 'min:1', 'max:999999'],
            'is_default' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_default' => $this->boolean('is_default')]);
    }
}
