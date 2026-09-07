<?php

namespace App\Http\Requests;

use App\MasterDataStatus;
use App\Models\SpendCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSpendCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('spend_category')) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique(SpendCategory::class)->ignore($this->route('spend_category'))],
            'status' => ['required', Rule::enum(MasterDataStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper($this->string('code')->trim()->toString())]);
    }
}
