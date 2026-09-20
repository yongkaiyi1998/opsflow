<?php

namespace App\AI;

use InvalidArgumentException;

final readonly class AiDocument
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $contents,
    ) {
        if (trim($this->filename) === '' || preg_match('/^[\w.+-]+$/D', $this->filename) !== 1) {
            throw new InvalidArgumentException('The AI document filename is invalid.');
        }

        if (preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/iD', $this->mimeType) !== 1) {
            throw new InvalidArgumentException('The AI document MIME type is invalid.');
        }

        if ($this->contents === '') {
            throw new InvalidArgumentException('The AI document must not be empty.');
        }
    }
}
