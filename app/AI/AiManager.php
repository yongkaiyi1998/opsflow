<?php

namespace App\AI;

use App\AI\Contracts\AiProvider;
use App\Exceptions\AI\AiDisabledException;
use Closure;

final class AiManager
{
    /** @param Closure(): AiProvider $providerResolver */
    public function __construct(
        private readonly bool $enabled,
        private readonly Closure $providerResolver,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        if (! $this->enabled) {
            throw new AiDisabledException('AI is disabled.');
        }

        return ($this->providerResolver)()->generate($request);
    }
}
