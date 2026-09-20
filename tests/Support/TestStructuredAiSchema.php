<?php

namespace Tests\Support;

use App\AI\Contracts\StructuredAiSchema;
use App\AI\Rules\DecimalString;
use Illuminate\Validation\Rule;

final class TestStructuredAiSchema implements StructuredAiSchema
{
    public function version(): string
    {
        return 'test-schema-v1';
    }

    public function rules(): array
    {
        return [
            'vendor' => ['required', 'string'],
            'status' => ['required', 'string', Rule::in(['MATCHED', 'UNMATCHED'])],
            'amount' => ['required', new DecimalString],
            'count' => ['required', 'integer'],
            'flags' => ['present', 'array'],
            'flags.*' => ['boolean'],
            'details' => ['required', 'array'],
            'details.reference' => ['required', 'string'],
        ];
    }
}
