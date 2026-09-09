<?php

namespace App\Http\Requests;

use App\MasterDataStatus;
use App\Models\Department;
use App\Models\SpendCategory;
use App\Models\SupplierInvoice;
use App\Models\Vendor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierInvoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('supplier_invoice')) ?? false;
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
            'invoice_no' => [
                'required', 'string', 'max:100',
                Rule::unique(SupplierInvoice::class, 'invoice_no')
                    ->where('vendor_id', $this->integer('vendor_id'))
                    ->ignore($this->route('supplier_invoice')),
            ],
            'vendor_id' => ['required', 'integer', Rule::exists(Vendor::class, 'id')->where('status', MasterDataStatus::Active->value)],
            'department_id' => ['required', 'integer', Rule::exists(Department::class, 'id')->where('status', MasterDataStatus::Active->value)],
            'category_id' => ['required', 'integer', Rule::exists(SpendCategory::class, 'id')->where('status', MasterDataStatus::Active->value)],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
            'description' => ['required', 'string', 'max:10000'],
            'tax_amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['required', 'array:description,quantity,unit_price'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'regex:/^\d{1,11}(?:\.\d{1,4})?$/', 'not_regex:/^0+(?:\.0+)?$/'],
            'items.*.unit_price' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/', 'not_regex:/^0+(?:\.0+)?$/'],
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
            'invoice_no' => $this->string('invoice_no')->trim()->toString(),
            'description' => $this->string('description')->trim()->toString(),
            'items' => $items,
        ]);
    }
}
