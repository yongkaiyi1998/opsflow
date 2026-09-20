<?php

namespace App\AI;

use App\Models\AiInteraction;

final readonly class WritingAssistance
{
    public function __construct(public string $draftText, public AiInteraction $interaction) {}
}
