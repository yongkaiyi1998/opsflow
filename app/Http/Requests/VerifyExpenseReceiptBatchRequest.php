<?php

namespace App\Http\Requests;

use App\MasterDataStatus;
use App\Models\ExpenseClaim;
use App\Models\IntakeBatch;
use App\Models\SpendCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyExpenseReceiptBatchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $batch = $this->route('intake_batch');

        return $batch instanceof IntakeBatch
            && ($this->user()?->can('view', $batch) ?? false)
            && ($this->user()?->can('create', ExpenseClaim::class) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'receipts' => ['required', 'array', 'min:1', 'max:50'],
            'receipts.*' => ['required', 'array:category_id,expense_date,merchant,description,amount,tax_amount'],
            'receipts.*.category_id' => ['required', 'integer', Rule::exists(SpendCategory::class, 'id')->where('status', MasterDataStatus::Active->value)],
            'receipts.*.expense_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'receipts.*.merchant' => ['nullable', 'string', 'max:255'],
            'receipts.*.description' => ['required', 'string', 'max:255'],
            'receipts.*.amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/', 'not_regex:/^0+(?:\.0+)?$/'],
            'receipts.*.tax_amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $receipts = collect($this->input('receipts', []))->map(function (mixed $receipt): mixed {
            if (! is_array($receipt)) {
                return $receipt;
            }

            $receipt['merchant'] = trim((string) ($receipt['merchant'] ?? '')) ?: null;
            $receipt['description'] = trim((string) ($receipt['description'] ?? ''));

            return $receipt;
        })->all();

        $this->merge([
            'title' => $this->string('title')->trim()->toString(),
            'description' => $this->string('description')->trim()->toString(),
            'receipts' => $receipts,
        ]);
    }
}
