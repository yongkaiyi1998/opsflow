<?php

namespace Tests\Unit;

use App\AI\StructuredOutputValidator;
use App\Exceptions\AI\InvalidStructuredAiResponseException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestStructuredAiSchema;
use Tests\TestCase;

class StructuredOutputValidatorTest extends TestCase
{
    public function test_validates_nested_structured_output_and_discards_undeclared_keys(): void
    {
        $result = $this->app->make(StructuredOutputValidator::class)->validate(json_encode([
            'vendor' => 'Acme',
            'status' => 'MATCHED',
            'amount' => '1250.40',
            'count' => 2,
            'details' => ['reference' => 'INV-100', 'private_note' => 'discard me too'],
            'flags' => [true, false],
            'undeclared' => 'discard me',
        ], JSON_THROW_ON_ERROR), new TestStructuredAiSchema);

        $this->assertSame([
            'vendor' => 'Acme',
            'status' => 'MATCHED',
            'amount' => '1250.40',
            'count' => 2,
            'details' => ['reference' => 'INV-100'],
            'flags' => [true, false],
        ], $result->data);
    }

    #[DataProvider('invalidResponses')]
    public function test_rejects_malformed_or_schema_invalid_output(string $content): void
    {
        $this->expectException(InvalidStructuredAiResponseException::class);

        $this->app->make(StructuredOutputValidator::class)->validate($content, new TestStructuredAiSchema);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidResponses(): iterable
    {
        yield 'invalid json' => ['not-json'];
        yield 'top-level list' => ['[]'];
        yield 'missing field' => ['{"vendor":"Acme"}'];
        yield 'invalid enum' => ['{"vendor":"Acme","status":"OTHER","amount":"1.00","count":1,"flags":[],"details":{"reference":"A"}}'];
        yield 'decimal is numeric' => ['{"vendor":"Acme","status":"MATCHED","amount":1.25,"count":1,"flags":[],"details":{"reference":"A"}}'];
        yield 'invalid array member' => ['{"vendor":"Acme","status":"MATCHED","amount":"1.25","count":1,"flags":["yes"],"details":{"reference":"A"}}'];
        yield 'object has wrong shape' => ['{"vendor":"Acme","status":"MATCHED","amount":"1.25","count":1,"flags":[],"details":[]}'];
    }
}
