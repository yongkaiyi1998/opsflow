<?php

namespace App\Providers;

use App\AI\AiManager;
use App\AI\Contracts\AiProvider;
use App\AI\Providers\OpenAiCompatibleProvider;
use App\Exceptions\AI\AiConfigurationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(AiProvider::class, function (): AiProvider {
            $provider = config('ai.provider');

            return match ($provider) {
                'openai-compatible' => new OpenAiCompatibleProvider(
                    baseUrl: (string) config('ai.base_url'),
                    model: (string) config('ai.model'),
                    apiKey: (string) config('ai.api_key'),
                    timeout: (int) config('ai.timeout'),
                    maxResponseBytes: (int) config('ai.max_response_bytes'),
                ),
                default => throw new AiConfigurationException('The configured AI provider is not supported.'),
            };
        });

        $this->app->singleton(AiManager::class, function (Application $app): AiManager {
            return new AiManager(
                enabled: (bool) config('ai.enabled'),
                providerResolver: static fn (): AiProvider => $app->make(AiProvider::class),
            );
        });
    }
}
