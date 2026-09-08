<?php

namespace App\Http\Requests;

use App\MasterDataStatus;
use App\Models\WorkflowTemplate;
use App\WorkflowModuleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkflowTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', WorkflowTemplate::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique(WorkflowTemplate::class)],
            'module_type' => ['required', Rule::enum(WorkflowModuleType::class), Rule::unique(WorkflowTemplate::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::enum(MasterDataStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper($this->string('code')->trim()->toString())]);
    }
}
