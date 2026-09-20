<?php

namespace App\Http\Requests;

use App\Models\ExpenseClaim;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SuggestExpenseClaimCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expenseClaim = $this->route('expense_claim');

        return $expenseClaim instanceof ExpenseClaim
            ? ($this->user()?->can('update', $expenseClaim) ?? false)
            : ($this->user()?->can('create', ExpenseClaim::class) ?? false);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'suggest_item_index' => ['required', 'integer', 'min:0', 'max:49'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['array'],
            'items.*.merchant' => ['nullable', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.amount' => ['nullable', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
        ];
    }
}
