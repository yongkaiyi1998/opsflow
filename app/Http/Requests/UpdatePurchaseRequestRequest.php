<?php

namespace App\Http\Requests;

use App\MasterDataStatus;
use App\Models\SpendCategory;
use App\Models\Vendor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('purchase_request')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'category_id' => ['required', 'integer', Rule::exists(SpendCategory::class, 'id')->where('status', MasterDataStatus::Active->value)],
            'vendor_id' => ['nullable', 'integer', Rule::exists(Vendor::class, 'id')->where('status', MasterDataStatus::Active->value)],
            'needed_by_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'tax_amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['required', 'array:description,quantity,unit_price'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_price' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))->map(function (mixed $item): mixed {
            if (! is_array($item)) {
                return $item;
            }

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
