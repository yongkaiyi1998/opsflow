<?php

namespace App\AI;

use Illuminate\Support\Str;
use Throwable;

final class AiPayloadSanitizer
{
    private const REDACTED = '[REDACTED]';

    private const TRUNCATED = '[TRUNCATED]';

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function metadata(array $metadata): array
    {
        $sanitized = $this->value($metadata, 0);

        return is_array($sanitized) ? $sanitized : [];
    }

    public function errorCode(Throwable $exception): string
    {
        return Str::limit(class_basename($exception), 100, '');
    }

    public function errorMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();
        $apiKey = (string) config('ai.api_key', '');

        if ($apiKey !== '') {
            $message = str_replace($apiKey, self::REDACTED, $message);
        }

        $message = preg_replace(
            '/(?i)(authorization|api[_-]?key|token|secret|password|cookie)\s*[:=]\s*(?:bearer\s+)?[^\s,;]+/',
            '$1='.self::REDACTED,
            $message,
        ) ?? 'AI execution failed.';
        $message = preg_replace('/(?i)bearer\s+[^\s,;]+/', 'Bearer '.self::REDACTED, $message)
            ?? 'AI execution failed.';

        return Str::limit($message, 1000, '');
    }

    private function value(mixed $value, int $depth): mixed
    {
        if ($depth >= 6) {
            return self::TRUNCATED;
        }

        if (is_array($value)) {
            $sanitized = [];

            foreach (array_slice($value, 0, 100, true) as $key => $item) {
                $sanitized[$key] = is_string($key) && $this->isSensitiveKey($key)
                    ? self::REDACTED
                    : $this->value($item, $depth + 1);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            return Str::limit($value, 2000, '');
        }

        if (is_null($value) || is_scalar($value)) {
            return $value;
        }

        return get_debug_type($value);
    }

    private function isSensitiveKey(string $key): bool
    {
        return Str::contains(Str::lower($key), [
            'authorization', 'api_key', 'apikey', 'password', 'secret', 'token', 'cookie',
        ]);
    }
}
