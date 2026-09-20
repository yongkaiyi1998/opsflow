<?php

namespace App\AI;

use InvalidArgumentException;

final readonly class AiRequest
{
    /** @param list<AiDocument> $documents */
    public function __construct(
        public string $prompt,
        public ?string $systemInstruction = null,
        public array $documents = [],
    ) {
        if (trim($this->prompt) === '') {
            throw new InvalidArgumentException('The AI prompt must not be empty.');
        }

        foreach ($this->documents as $document) {
            if (! $document instanceof AiDocument) {
                throw new InvalidArgumentException('AI request documents must be AiDocument instances.');
            }
        }
    }

    /** @return list<array{role: string, content: string}> */
    public function messages(): array
    {
        $messages = [];

        if ($this->systemInstruction !== null && trim($this->systemInstruction) !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $this->systemInstruction,
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $this->prompt,
        ];

        return $messages;
    }
}
