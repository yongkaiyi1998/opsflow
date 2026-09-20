<?php

namespace App\Services;

use App\AI\AiExecutionResult;
use App\AI\AiManager;
use App\AI\AiRequest;
use App\AI\Contracts\StructuredAiSchema;
use App\AI\StructuredOutputValidator;
use App\Exceptions\AI\AiConfigurationException;
use App\Models\AiInteraction;
use Throwable;

class AiExecutionService
{
    public function __construct(
        private readonly AiManager $manager,
        private readonly AiInteractionService $interactions,
        private readonly StructuredOutputValidator $structuredOutputValidator,
    ) {}

    public function execute(
        AiInteraction $interaction,
        AiRequest $request,
        ?StructuredAiSchema $schema = null,
    ): ?AiExecutionResult {
        $processingInteraction = $this->interactions->beginProcessing($interaction);

        if ($processingInteraction === null) {
            return null;
        }

        $startedAt = hrtime(true);

        try {
            $this->ensureSchemaMatches($processingInteraction, $schema);
            $response = $this->manager->generate($request);
            $structuredResult = $schema === null
                ? null
                : $this->structuredOutputValidator->validate($response->content, $schema);
            $completedInteraction = $this->interactions->markSucceeded(
                $processingInteraction,
                $structuredResult?->data,
                $this->elapsedMilliseconds($startedAt),
            );

            return new AiExecutionResult($completedInteraction, $response, $structuredResult);
        } catch (Throwable $exception) {
            $this->interactions->markFailed(
                $processingInteraction,
                $exception,
                $this->elapsedMilliseconds($startedAt),
            );

            throw $exception;
        }
    }

    private function ensureSchemaMatches(AiInteraction $interaction, ?StructuredAiSchema $schema): void
    {
        if ($interaction->schema_version === null && $schema === null) {
            return;
        }

        if ($schema === null || $interaction->schema_version !== $schema->version()) {
            throw new AiConfigurationException('The structured output schema version does not match the AI interaction.');
        }
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return (int) max(0, round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
