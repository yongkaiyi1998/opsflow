<?php

namespace App\AI;

use App\AI\Contracts\StructuredAiSchema;

final class SupplierInvoiceExtractionSchema implements StructuredAiSchema
{
    public const FEATURE = 'supplier_invoice_document_extraction';

    public const PROMPT_VERSION = 'v1';

    public const SCHEMA_VERSION = 'v1';

    public function version(): string
    {
        return self::SCHEMA_VERSION;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $money = ['present', 'nullable', 'string', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/D'];

        return [
            'vendor_name' => ['present', 'nullable', 'string', 'max:255'],
            'invoice_no' => ['present', 'nullable', 'string', 'max:100'],
            'invoice_date' => ['present', 'nullable', 'date_format:Y-m-d'],
            'due_date' => ['present', 'nullable', 'date_format:Y-m-d'],
            'currency' => ['present', 'nullable', 'string', 'regex:/^[A-Z]{3}$/D'],
            'subtotal' => $money,
            'tax_amount' => $money,
            'total_amount' => $money,
            'line_items' => ['present', 'array', 'max:50'],
            'line_items.*' => ['required', 'array:description,quantity,unit_price,subtotal'],
            'line_items.*.description' => ['present', 'nullable', 'string', 'max:255'],
            'line_items.*.quantity' => [
                'present', 'nullable', 'string',
                'regex:/^\d{1,11}(?:\.\d{1,4})?$/D',
                'not_regex:/^0+(?:\.0+)?$/D',
            ],
            'line_items.*.unit_price' => $money,
            'line_items.*.subtotal' => $money,
        ];
    }

    public static function featureVersion(): AiFeatureVersion
    {
        return new AiFeatureVersion(self::FEATURE, self::PROMPT_VERSION, self::SCHEMA_VERSION);
    }
}
