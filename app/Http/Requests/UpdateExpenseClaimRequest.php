<?php

namespace App\Http\Requests;

use App\MasterDataStatus;
use App\Models\SpendCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('expense_claim')) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['required', 'array:id,category_id,expense_date,merchant,description,amount,tax_amount'],
            'items.*.id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'items.*.category_id' => ['required', 'integer', Rule::exists(SpendCategory::class, 'id')->where('status', MasterDataStatus::Active->value)],
            'items.*.expense_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'items.*.merchant' => ['nullable', 'string', 'max:255'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/', 'not_regex:/^0+(?:\.0+)?$/'],
            'items.*.tax_amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))->map(function (mixed $item): mixed {
            if (! is_array($item)) {
                return $item;
            }

            $item['merchant'] = trim((string) ($item['merchant'] ?? '')) ?: null;
            $item['description'] = trim((string) ($item['description'] ?? ''));

            return $item;
        })->all();

        $this->merge([
            'title' => $this->string('title')->trim()->toString(),
            'description' => $this->string('description')->trim()->toString(),
            'items' => $items,
        ]);
    }
}
