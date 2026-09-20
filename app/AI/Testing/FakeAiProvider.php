<?php

namespace App\AI\Testing;

use App\AI\AiRequest;
use App\AI\AiResponse;
use App\AI\Contracts\AiProvider;
use LogicException;
use Throwable;

final class FakeAiProvider implements AiProvider
{
    /** @var list<AiResponse|Throwable> */
    private array $responses;

    /** @var list<AiRequest> */
    private array $requests = [];

    /** @param list<AiResponse|Throwable> $responses */
    public function __construct(array $responses = [])
    {
        $this->responses = array_values($responses);
    }

    public static function respondingWith(string ...$responses): self
    {
        return new self(array_map(
            static fn (string $content): AiResponse => new AiResponse($content),
            $responses,
        ));
    }

    public function generate(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);

        if ($response === null) {
            throw new LogicException('No fake AI response is available.');
        }

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }

    /** @return list<AiRequest> */
    public function requests(): array
    {
        return $this->requests;
    }
}
