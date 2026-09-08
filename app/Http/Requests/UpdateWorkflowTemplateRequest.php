<?php

namespace App\Http\Requests;

use App\MasterDataStatus;
use App\Models\WorkflowTemplate;
use App\WorkflowModuleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkflowTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('workflow_template')) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique(WorkflowTemplate::class)->ignore($this->route('workflow_template'))],
            'module_type' => ['required', Rule::enum(WorkflowModuleType::class), Rule::unique(WorkflowTemplate::class)->ignore($this->route('workflow_template'))],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::enum(MasterDataStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper($this->string('code')->trim()->toString())]);
    }
}
