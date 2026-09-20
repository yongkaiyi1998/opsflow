<?php

namespace App\AI;

use App\Models\AiInteraction;

final readonly class RecordAnalysis
{
    /**
     * @param  list<string>  $bullets
     * @param  list<array{title: string, explanation: string, severity_label: string}>  $flags
     */
    public function __construct(
        public ?string $headline,
        public array $bullets,
        public array $flags,
        public AiInteraction $interaction,
        public bool $stale,
    ) {}
}
