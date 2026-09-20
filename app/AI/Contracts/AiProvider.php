<?php

namespace App\AI\Contracts;

use App\AI\AiRequest;
use App\AI\AiResponse;

interface AiProvider
{
    public function generate(AiRequest $request): AiResponse;
}
