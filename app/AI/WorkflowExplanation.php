<?php

namespace App\AI;

use App\Models\AiInteraction;

final readonly class WorkflowExplanation
{
    /** @param list<string> $keyPoints */
    public function __construct(public string $headline, public string $explanation, public array $keyPoints, public ?AiInteraction $interaction = null) {}
}
