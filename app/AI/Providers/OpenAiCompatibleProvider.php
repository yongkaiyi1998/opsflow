<?php

namespace App\AI\Providers;

use App\AI\AiRequest;
use App\AI\AiResponse;
use App\AI\Contracts\AiProvider;
use App\Exceptions\AI\AiConfigurationException;
use App\Exceptions\AI\AiConnectionException;
use App\Exceptions\AI\AiProviderRequestException;
use App\Exceptions\AI\AiTimeoutException;
use App\Exceptions\AI\MalformedAiResponseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class OpenAiCompatibleProvider implements AiProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly string $apiKey,
        private readonly int $timeout,
        private readonly int $maxResponseBytes,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        $this->validateConfiguration();

        $pendingRequest = Http::acceptJson()
            ->asJson()
            ->connectTimeout(min(5, $this->timeout))
            ->timeout($this->timeout);

        if ($this->apiKey !== '') {
            $pendingRequest = $pendingRequest->withToken($this->apiKey);
        }

        try {
            $response = $pendingRequest->post(
                rtrim($this->baseUrl, '/').'/chat/completions',
                [
                    'model' => $this->model,
                    'messages' => $this->messages($request),
                ],
            );
        } catch (ConnectionException $exception) {
            if ($this->isTimeout($exception)) {
                throw new AiTimeoutException('The AI provider request timed out.', previous: $exception);
            }

            throw new AiConnectionException('The AI provider could not be reached.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new AiProviderRequestException($response->status());
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content)) {
            throw new MalformedAiResponseException('The AI provider returned a malformed response.');
        }

        if (strlen($content) > $this->maxResponseBytes) {
            throw new MalformedAiResponseException('The AI provider response exceeded the configured size limit.');
        }

        return new AiResponse($content);
    }

    private function validateConfiguration(): void
    {
        $scheme = parse_url($this->baseUrl, PHP_URL_SCHEME);

        if (! filter_var($this->baseUrl, FILTER_VALIDATE_URL)
            || ! in_array($scheme, ['http', 'https'], true)
            || parse_url($this->baseUrl, PHP_URL_USER) !== null
            || parse_url($this->baseUrl, PHP_URL_PASS) !== null
            || parse_url($this->baseUrl, PHP_URL_QUERY) !== null
            || parse_url($this->baseUrl, PHP_URL_FRAGMENT) !== null) {
            throw new AiConfigurationException('The AI base URL is not configured correctly.');
        }

        if (trim($this->model) === '') {
            throw new AiConfigurationException('The AI model is not configured.');
        }

        if ($this->timeout < 1) {
            throw new AiConfigurationException('The AI timeout must be at least one second.');
        }

        if ($this->maxResponseBytes < 1024) {
            throw new AiConfigurationException('The AI response size limit must be at least 1024 bytes.');
        }
    }

    /** @return list<array{role: string, content: string|list<array<string, mixed>>}> */
    private function messages(AiRequest $request): array
    {
        if ($request->documents === []) {
            return $request->messages();
        }

        $messages = [];

        if ($request->systemInstruction !== null && trim($request->systemInstruction) !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $request->systemInstruction,
            ];
        }

        $content = [[
            'type' => 'text',
            'text' => $request->prompt,
        ]];

        foreach ($request->documents as $document) {
            $dataUri = "data:{$document->mimeType};base64,".base64_encode($document->contents);
            $content[] = str_starts_with($document->mimeType, 'image/')
                ? [
                    'type' => 'image_url',
                    'image_url' => ['url' => $dataUri],
                ]
                : [
                    'type' => 'file',
                    'file' => [
                        'filename' => $document->filename,
                        'file_data' => $dataUri,
                    ],
                ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $content,
        ];

        return $messages;
    }

    private function isTimeout(ConnectionException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'curl error 28');
    }
}
