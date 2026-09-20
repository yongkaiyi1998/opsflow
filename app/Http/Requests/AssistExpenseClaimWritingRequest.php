<?php

namespace App\Http\Requests;

use App\Models\ExpenseClaim;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AssistExpenseClaimWritingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $record = $this->route('expense_claim');

        return $record instanceof ExpenseClaim ? ($this->user()?->can('update', $record) ?? false) : ($this->user()?->can('create', ExpenseClaim::class) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['title' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'], 'items' => ['nullable', 'array', 'max:50'], 'items.*' => ['array'], 'items.*.merchant' => ['nullable', 'string', 'max:255'], 'items.*.description' => ['nullable', 'string', 'max:255']];
    }
}
