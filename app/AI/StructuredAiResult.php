<?php

namespace App\AI;

final readonly class StructuredAiResult
{
    /** @param array<string, mixed> $data */
    public function __construct(public array $data) {}
}
