<?php

namespace App\AI;

use InvalidArgumentException;

final readonly class AiFeatureVersion
{
    public function __construct(
        public string $feature,
        public string $promptVersion,
        public ?string $schemaVersion = null,
    ) {
        if (trim($this->feature) === '' || trim($this->promptVersion) === '') {
            throw new InvalidArgumentException('AI feature and prompt versions must not be empty.');
        }

        if ($this->schemaVersion !== null && trim($this->schemaVersion) === '') {
            throw new InvalidArgumentException('The AI schema version must be null or non-empty.');
        }
    }
}
