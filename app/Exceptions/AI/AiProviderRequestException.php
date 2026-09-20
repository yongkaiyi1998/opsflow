<?php

namespace App\Exceptions\AI;

class AiProviderRequestException extends AiException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct("The AI provider returned HTTP status {$status}.");
    }
}
