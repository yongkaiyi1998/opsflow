<?php

namespace App\Http\Requests;

use App\MasterDataStatus;
use App\Models\Department;
use App\UserStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('department')) ?? false;
    }

    public function rules(): array
    {
        $department = $this->route('department');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique(Department::class)->ignore($department)],
            'manager_id' => ['nullable', Rule::exists('users', 'id')->where(
                fn (Builder $query): Builder => $query->where('status', UserStatus::Active->value)
                    ->when($department->manager_id, fn (Builder $query): Builder => $query->orWhere('id', $department->manager_id)),
            )],
            'status' => ['required', Rule::enum(MasterDataStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper($this->string('code')->trim()->toString())]);
    }
}
