<?php

namespace App\AI;

use App\AI\Contracts\StructuredAiSchema;
use Closure;

final class WritingAssistanceSchema implements StructuredAiSchema
{
    public const PROMPT_VERSION = 'v1';

    public const SCHEMA_VERSION = 'v1';

    public function version(): string
    {
        return self::SCHEMA_VERSION;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $plainText = static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && preg_match('/<[^>]+>|\[(?:[^\]]+)\]\([^)]+\)/', $value) === 1) {
                $fail('The writing suggestion must be plain text.');
            }
        };

        return ['draft_text' => ['required', 'string', 'min:1', 'max:2000', $plainText]];
    }
}
