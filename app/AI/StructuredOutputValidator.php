<?php

namespace App\AI;

use App\AI\Contracts\StructuredAiSchema;
use App\Exceptions\AI\InvalidStructuredAiResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;

final class StructuredOutputValidator
{
    public function validate(string $content, StructuredAiSchema $schema): StructuredAiResult
    {
        try {
            $decodedObject = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidStructuredAiResponseException('The AI response is not valid JSON.', previous: $exception);
        }

        if (! $decodedObject instanceof stdClass || ! is_array($decoded)) {
            throw new InvalidStructuredAiResponseException('The AI response must be a JSON object.');
        }

        try {
            $validated = Validator::make($decoded, $schema->rules())->validate();
        } catch (ValidationException $exception) {
            throw new InvalidStructuredAiResponseException('The AI response does not match the expected schema.', previous: $exception);
        }

        return new StructuredAiResult($validated);
    }
}
