<?php

namespace App\Http\Requests;

use App\MasterDataStatus;
use App\Models\PurchaseRequest;
use App\Models\SpendCategory;
use App\Models\Vendor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssistPurchaseRequestWritingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $record = $this->route('purchase_request');

        return $record instanceof PurchaseRequest ? ($this->user()?->can('update', $record) ?? false) : ($this->user()?->can('create', PurchaseRequest::class) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'],
            'category_id' => ['nullable', 'integer', Rule::exists(SpendCategory::class, 'id')->where('status', MasterDataStatus::Active->value)],
            'vendor_id' => ['nullable', 'integer', Rule::exists(Vendor::class, 'id')->where('status', MasterDataStatus::Active->value)],
            'items' => ['nullable', 'array', 'max:50'], 'items.*' => ['array'], 'items.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
