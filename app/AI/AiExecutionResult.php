<?php

namespace App\AI;

use App\Models\AiInteraction;

final readonly class AiExecutionResult
{
    public function __construct(
        public AiInteraction $interaction,
        public AiResponse $response,
        public ?StructuredAiResult $structuredResult,
    ) {}
}
