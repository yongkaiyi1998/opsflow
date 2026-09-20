<?php

namespace App\Http\Requests;

use App\MasterDataStatus;
use App\Models\PurchaseRequest;
use App\Models\Vendor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SuggestPurchaseRequestCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $purchaseRequest = $this->route('purchase_request');

        return $purchaseRequest instanceof PurchaseRequest
            ? ($this->user()?->can('update', $purchaseRequest) ?? false)
            : ($this->user()?->can('create', PurchaseRequest::class) ?? false);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'vendor_id' => ['nullable', 'integer', Rule::exists(Vendor::class, 'id')->where('status', MasterDataStatus::Active->value)],
            'items' => ['nullable', 'array', 'max:50'],
            'items.*' => ['array'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
