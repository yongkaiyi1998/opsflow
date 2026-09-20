<?php

namespace App\AI;

use App\AI\Contracts\StructuredAiSchema;

final class ExpenseReceiptExtractionSchema implements StructuredAiSchema
{
    public const FEATURE = 'expense_receipt_document_extraction';

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
            'merchant' => ['present', 'nullable', 'string', 'max:255'],
            'transaction_date' => ['present', 'nullable', 'date_format:Y-m-d'],
            'description' => ['present', 'nullable', 'string', 'max:255'],
            'currency' => ['present', 'nullable', 'string', 'regex:/^[A-Z]{3}$/D'],
            'amount' => $money,
            'tax_amount' => $money,
        ];
    }

    public static function featureVersion(): AiFeatureVersion
    {
        return new AiFeatureVersion(self::FEATURE, self::PROMPT_VERSION, self::SCHEMA_VERSION);
    }
}
