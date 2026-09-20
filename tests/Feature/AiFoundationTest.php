<?php

namespace Tests\Feature;

use App\AI\AiManager;
use App\AI\AiRequest;
use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Exceptions\AI\AiConfigurationException;
use App\Exceptions\AI\AiConnectionException;
use App\Exceptions\AI\AiDisabledException;
use App\Exceptions\AI\AiProviderRequestException;
use App\Exceptions\AI\AiTimeoutException;
use App\Exceptions\AI\MalformedAiResponseException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AiFoundationTest extends TestCase
{
    public function test_disabled_ai_fails_before_resolving_a_provider_or_sending_a_request(): void
    {
        config()->set('ai.enabled', false);
        config()->set('ai.provider', null);
        Http::preventStrayRequests();

        $this->expectException(AiDisabledException::class);

        $this->manager()->generate(new AiRequest('Summarize this request.'));
    }

    public function test_openai_compatible_provider_returns_content_without_requiring_an_api_key(): void
    {
        $this->configureProvider();
        Http::preventStrayRequests();
        Http::fake([
            'https://ai.example.test/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Deterministic response']],
                ],
            ]),
        ]);

        $response = $this->manager()->generate(new AiRequest(
            prompt: 'Explain the persisted route.',
            systemInstruction: 'Use only the supplied facts.',
        ));

        $this->assertSame('Deterministic response', $response->content);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://ai.example.test/v1/chat/completions'
                && $request['model'] === 'test-model'
                && $request['messages'] === [
                    ['role' => 'system', 'content' => 'Use only the supplied facts.'],
                    ['role' => 'user', 'content' => 'Explain the persisted route.'],
                ]
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_local_http_endpoint_works_without_an_api_key(): void
    {
        $this->configureProvider(baseUrl: 'http://127.0.0.1:1234/v1');
        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:1234/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Local response']]],
            ]),
        ]);

        $this->assertSame('Local response', $this->manager()->generate(new AiRequest('Local test'))->content);
        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
    }

    public function test_configured_api_key_is_sent_as_a_bearer_token(): void
    {
        $this->configureProvider(apiKey: 'test-secret-key');
        Http::preventStrayRequests();
        Http::fake([
            'https://ai.example.test/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Response']],
                ],
            ]),
        ]);

        $this->manager()->generate(new AiRequest('Test prompt'));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-secret-key'));
    }

    public function test_fake_provider_supplies_deterministic_responses_and_records_requests(): void
    {
        config()->set('ai.enabled', true);
        $fake = FakeAiProvider::respondingWith('First response', 'Second response');
        $this->app->instance(AiProvider::class, $fake);
        $firstRequest = new AiRequest('First prompt');
        $secondRequest = new AiRequest('Second prompt');

        $manager = $this->manager(forgetProvider: false);
        $firstResponse = $manager->generate($firstRequest);
        $secondResponse = $manager->generate($secondRequest);

        $this->assertSame('First response', $firstResponse->content);
        $this->assertSame('Second response', $secondResponse->content);
        $this->assertSame([$firstRequest, $secondRequest], $fake->requests());
    }

    public function test_fake_provider_can_reproduce_a_provider_failure(): void
    {
        config()->set('ai.enabled', true);
        $failure = new RuntimeException('Provider unavailable.');
        $this->app->instance(AiProvider::class, new FakeAiProvider([$failure]));

        $this->expectExceptionObject($failure);

        $this->manager(forgetProvider: false)->generate(new AiRequest('Test prompt'));
    }

    public function test_missing_provider_configuration_fails_explicitly(): void
    {
        config()->set('ai.enabled', true);
        config()->set('ai.provider', null);

        $this->expectException(AiConfigurationException::class);

        $this->manager()->generate(new AiRequest('Test prompt'));
    }

    public function test_invalid_provider_settings_fail_before_an_http_request(): void
    {
        $this->configureProvider(baseUrl: '', model: '');
        Http::preventStrayRequests();

        $this->expectException(AiConfigurationException::class);

        $this->manager()->generate(new AiRequest('Test prompt'));
    }

    public function test_base_url_rejects_embedded_credentials_and_query_parameters(): void
    {
        Http::preventStrayRequests();

        foreach (['https://user:secret@ai.example.test/v1', 'https://ai.example.test/v1?token=secret'] as $baseUrl) {
            $this->configureProvider(baseUrl: $baseUrl);

            try {
                $this->manager()->generate(new AiRequest('Test prompt'));
                $this->fail('Expected unsafe base URL configuration to fail.');
            } catch (AiConfigurationException) {
                $this->assertTrue(true);
            }
        }

        Http::assertNothingSent();
    }

    public function test_oversized_provider_content_is_rejected(): void
    {
        $this->configureProvider(maxResponseBytes: 1024);
        Http::fake([
            'https://ai.example.test/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => str_repeat('x', 1025)]]],
            ]),
        ]);

        $this->expectException(MalformedAiResponseException::class);
        $this->expectExceptionMessage('The AI provider response exceeded the configured size limit.');

        $this->manager()->generate(new AiRequest('Test prompt'));
    }

    public function test_non_success_response_raises_a_sanitized_application_exception(): void
    {
        $this->configureProvider(apiKey: 'must-not-appear');
        Http::preventStrayRequests();
        Http::fake([
            'https://ai.example.test/v1/chat/completions' => Http::response([
                'error' => ['message' => 'private provider detail'],
            ], 429),
        ]);

        try {
            $this->manager()->generate(new AiRequest('Test prompt'));
            $this->fail('Expected the provider request to fail.');
        } catch (AiProviderRequestException $exception) {
            $this->assertSame(429, $exception->status);
            $this->assertSame('The AI provider returned HTTP status 429.', $exception->getMessage());
            $this->assertStringNotContainsString('private provider detail', $exception->getMessage());
            $this->assertStringNotContainsString('must-not-appear', $exception->getMessage());
        }
    }

    public function test_malformed_provider_response_fails_explicitly(): void
    {
        $this->configureProvider();
        Http::preventStrayRequests();
        Http::fake([
            'https://ai.example.test/v1/chat/completions' => Http::response([
                'choices' => [],
            ]),
        ]);

        $this->expectException(MalformedAiResponseException::class);

        $this->manager()->generate(new AiRequest('Test prompt'));
    }

    public function test_connection_failure_is_translated_to_an_application_exception(): void
    {
        $this->configureProvider();
        Http::preventStrayRequests();
        Http::fake([
            'https://ai.example.test/v1/chat/completions' => Http::failedConnection('Could not connect to the provider.'),
        ]);

        $this->expectException(AiConnectionException::class);
        $this->expectExceptionMessage('The AI provider could not be reached.');

        $this->manager()->generate(new AiRequest('Test prompt'));
    }

    public function test_timeout_is_translated_to_a_distinct_application_exception(): void
    {
        $this->configureProvider();
        Http::preventStrayRequests();
        Http::fake([
            'https://ai.example.test/v1/chat/completions' => Http::failedConnection('cURL error 28: Operation timed out.'),
        ]);

        $this->expectException(AiTimeoutException::class);
        $this->expectExceptionMessage('The AI provider request timed out.');

        $this->manager()->generate(new AiRequest('Test prompt'));
    }

    private function configureProvider(
        string $baseUrl = 'https://ai.example.test/v1',
        string $model = 'test-model',
        string $apiKey = '',
        int $timeout = 10,
        int $maxResponseBytes = 1048576,
    ): void {
        config()->set([
            'ai.enabled' => true,
            'ai.provider' => 'openai-compatible',
            'ai.base_url' => $baseUrl,
            'ai.model' => $model,
            'ai.api_key' => $apiKey,
            'ai.timeout' => $timeout,
            'ai.max_response_bytes' => $maxResponseBytes,
        ]);
    }

    private function manager(bool $forgetProvider = true): AiManager
    {
        $this->app->forgetInstance(AiManager::class);

        if ($forgetProvider) {
            $this->app->forgetInstance(AiProvider::class);
        }

        return $this->app->make(AiManager::class);
    }
}
