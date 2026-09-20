<?php

namespace Tests\Feature;

use App\AI\AiFeatureVersion;
use App\AI\AiManager;
use App\AI\AiRequest;
use App\AI\AiResponse;
use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\AiInteractionStatus;
use App\Exceptions\AI\AiInteractionInProgressException;
use App\Exceptions\AI\InvalidStructuredAiResponseException;
use App\Jobs\ExecuteAiInteraction;
use App\Models\AiInteraction;
use App\Models\User;
use App\Services\AiExecutionService;
use App\Services\AiInteractionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use Tests\Support\TestStructuredAiSchema;
use Tests\TestCase;

class AiExecutionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_compact_trace_metadata_with_subject_creator_and_redaction(): void
    {
        config()->set(['ai.provider' => 'openai-compatible', 'ai.model' => 'trace-model']);
        $user = User::factory()->create();

        $interaction = $this->app->make(AiInteractionService::class)->create(
            new AiFeatureVersion('test.feature', 'prompt-v1', 'test-schema-v1'),
            subject: $user,
            creator: $user,
            inputHash: hash('sha256', 'stable input'),
            requestMetadata: [
                'document_count' => 2,
                'nested' => [
                    'Authorization' => 'Bearer should-not-persist',
                    'api_key' => 'should-not-persist',
                ],
            ],
            idempotencyKey: 'test-feature-run-1',
        );

        $this->assertSame(AiInteractionStatus::Pending, $interaction->status);
        $this->assertSame('test.feature', $interaction->feature);
        $this->assertSame('openai-compatible', $interaction->provider);
        $this->assertSame('trace-model', $interaction->model);
        $this->assertSame(User::class, $interaction->subject_type);
        $this->assertSame($user->id, $interaction->subject_id);
        $this->assertSame($user->id, $interaction->created_by);
        $this->assertSame([
            'document_count' => 2,
            'nested' => [
                'Authorization' => '[REDACTED]',
                'api_key' => '[REDACTED]',
            ],
        ], $interaction->request_metadata);
    }

    public function test_executes_and_persists_only_validated_structured_output(): void
    {
        Http::preventStrayRequests();
        $fake = new FakeAiProvider([
            new AiResponse('{"vendor":"Acme","status":"MATCHED","amount":"10.25","count":1,"flags":[true],"details":{"reference":"INV-1"},"extra":"discard"}'),
        ]);
        $this->useFakeProvider($fake);
        $interaction = $this->interaction();

        $result = $this->app->make(AiExecutionService::class)->execute(
            $interaction,
            new AiRequest('Return structured test data.'),
            new TestStructuredAiSchema,
        );

        $this->assertNotNull($result);
        $this->assertSame('Acme', $result->structuredResult?->data['vendor']);
        $this->assertSame(AiInteractionStatus::Succeeded, $result->interaction->status);
        $this->assertSame([
            'vendor' => 'Acme',
            'status' => 'MATCHED',
            'amount' => '10.25',
            'count' => 1,
            'details' => ['reference' => 'INV-1'],
            'flags' => [true],
        ], $result->interaction->response_payload);
        $this->assertIsInt($result->interaction->latency_ms);
        $this->assertNull($result->interaction->error_code);
    }

    public function test_malformed_structured_output_records_a_safe_failure(): void
    {
        Http::preventStrayRequests();
        $this->useFakeProvider(FakeAiProvider::respondingWith('{"vendor":"missing required fields"}'));
        $interaction = $this->interaction();

        try {
            $this->app->make(AiExecutionService::class)->execute(
                $interaction,
                new AiRequest('Return structured test data.'),
                new TestStructuredAiSchema,
            );
            $this->fail('Expected structured validation to fail.');
        } catch (InvalidStructuredAiResponseException) {
            $interaction->refresh();
        }

        $this->assertSame(AiInteractionStatus::Failed, $interaction->status);
        $this->assertSame('InvalidStructuredAiResponseException', $interaction->error_code);
        $this->assertSame('The AI response does not match the expected schema.', $interaction->error_message);
        $this->assertNull($interaction->response_payload);
    }

    public function test_provider_failure_is_redacted_before_persistence(): void
    {
        Http::preventStrayRequests();
        config()->set('ai.api_key', 'configured-secret-value');
        $this->useFakeProvider(new FakeAiProvider([
            new RuntimeException('Authorization: Bearer exposed-token api_key=configured-secret-value cookie=session-value'),
        ]));
        $interaction = $this->interaction();

        try {
            $this->app->make(AiExecutionService::class)->execute(
                $interaction,
                new AiRequest('Return structured test data.'),
                new TestStructuredAiSchema,
            );
            $this->fail('Expected the fake provider to fail.');
        } catch (RuntimeException) {
            $interaction->refresh();
        }

        $this->assertSame(AiInteractionStatus::Failed, $interaction->status);
        $this->assertStringNotContainsString('exposed-token', $interaction->error_message);
        $this->assertStringNotContainsString('configured-secret-value', $interaction->error_message);
        $this->assertStringNotContainsString('session-value', $interaction->error_message);
        $this->assertStringContainsString('[REDACTED]', $interaction->error_message);
    }

    public function test_feature_idempotency_key_reuses_the_existing_trace_record(): void
    {
        $service = $this->app->make(AiInteractionService::class);
        $feature = new AiFeatureVersion('test.feature', 'prompt-v1', 'test-schema-v1');

        $first = $service->create($feature, idempotencyKey: 'same-run');
        $second = $service->create($feature, idempotencyKey: 'same-run');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, AiInteraction::query()->count());
    }

    public function test_idempotency_key_cannot_be_reused_for_a_different_subject(): void
    {
        $service = $this->app->make(AiInteractionService::class);
        $feature = new AiFeatureVersion('test.feature', 'prompt-v1', 'test-schema-v1');
        $firstSubject = User::factory()->create();
        $secondSubject = User::factory()->create();
        $service->create($feature, subject: $firstSubject, idempotencyKey: 'subject-run');

        try {
            $service->create($feature, subject: $secondSubject, idempotencyKey: 'subject-run');
            $this->fail('Expected the idempotency collision to fail.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'The AI idempotency key is already associated with a different operation.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(1, AiInteraction::query()->count());
    }

    public function test_job_retry_reuses_one_interaction_and_can_succeed_after_failure(): void
    {
        Http::preventStrayRequests();
        $fake = new FakeAiProvider([
            new RuntimeException('Temporary provider outage.'),
            new AiResponse('{"vendor":"Acme","status":"MATCHED","amount":"5.00","count":1,"flags":[],"details":{"reference":"INV-2"}}'),
        ]);
        $this->useFakeProvider($fake);
        $interaction = $this->interaction(idempotencyKey: 'retry-run');
        $job = new ExecuteAiInteraction(
            $interaction->id,
            new AiRequest('Return structured test data.'),
            new TestStructuredAiSchema,
        );
        $execution = $this->app->make(AiExecutionService::class);

        try {
            $job->handle($execution);
        } catch (RuntimeException) {
            // The queue retries this same job and interaction after a transient failure.
        }
        $job->handle($execution);

        $interaction->refresh();
        $this->assertSame(AiInteractionStatus::Succeeded, $interaction->status);
        $this->assertSame(1, AiInteraction::query()->count());
        $this->assertCount(2, $fake->requests());
        $this->assertNull($interaction->error_code);
        $this->assertNull($interaction->error_message);
        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertSame([5, 30], $job->backoff());
    }

    public function test_completed_interaction_is_not_executed_again(): void
    {
        Http::preventStrayRequests();
        $fake = FakeAiProvider::respondingWith(
            '{"vendor":"Acme","status":"MATCHED","amount":"5.00","count":1,"flags":[],"details":{"reference":"INV-3"}}',
        );
        $this->useFakeProvider($fake);
        $interaction = $this->interaction();
        $execution = $this->app->make(AiExecutionService::class);

        $first = $execution->execute($interaction, new AiRequest('First attempt.'), new TestStructuredAiSchema);
        $second = $execution->execute($interaction, new AiRequest('Duplicate attempt.'), new TestStructuredAiSchema);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertCount(1, $fake->requests());
    }

    public function test_fresh_processing_interaction_rejects_a_concurrent_execution(): void
    {
        Http::preventStrayRequests();
        $fake = FakeAiProvider::respondingWith('unused');
        $this->useFakeProvider($fake);
        $interaction = $this->interaction();
        $interaction->update([
            'status' => AiInteractionStatus::Processing,
            'processing_started_at' => now(),
        ]);

        $this->expectException(AiInteractionInProgressException::class);

        try {
            $this->app->make(AiExecutionService::class)->execute(
                $interaction,
                new AiRequest('Concurrent attempt.'),
                new TestStructuredAiSchema,
            );
        } finally {
            $this->assertCount(0, $fake->requests());
        }
    }

    private function interaction(?string $idempotencyKey = null): AiInteraction
    {
        config()->set(['ai.provider' => 'fake', 'ai.model' => 'fake-model']);

        return $this->app->make(AiInteractionService::class)->create(
            new AiFeatureVersion('test.feature', 'prompt-v1', 'test-schema-v1'),
            idempotencyKey: $idempotencyKey,
        );
    }

    private function useFakeProvider(FakeAiProvider $fake): void
    {
        config()->set('ai.enabled', true);
        $this->app->instance(AiProvider::class, $fake);
        $this->app->forgetInstance(AiManager::class);
    }
}
