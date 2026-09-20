<?php

namespace App\AI\Contracts;

interface StructuredAiSchema
{
    public function version(): string;

    /** @return array<string, mixed> */
    public function rules(): array;
}
