<?php

namespace App\Http\Requests;

use App\Models\ExpenseClaim;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreExpenseReceiptIntakeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', ExpenseClaim::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'submission_key' => ['required', 'uuid'],
            'documents' => ['required', 'array', 'min:1', 'max:'.config('document_intake.max_files')],
            'documents.*' => [
                'required',
                File::types(config('document_intake.types'))->max(config('document_intake.max_size')),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'documents.required' => 'Select at least one receipt.',
            'documents.max' => 'A batch may contain at most 20 receipts.',
            'documents.*.max' => 'Each receipt may not be larger than 10 MB.',
        ];
    }
}
