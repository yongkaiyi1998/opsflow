<?php

namespace App\Services;

use App\AI\AiFeatureVersion;
use App\AI\AiPayloadSanitizer;
use App\AiInteractionStatus;
use App\Exceptions\AI\AiInteractionInProgressException;
use App\Models\AiInteraction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Throwable;

class AiInteractionService
{
    private const PROCESSING_LEASE_SECONDS = 75;

    public function __construct(private readonly AiPayloadSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $requestMetadata
     */
    public function create(
        AiFeatureVersion $featureVersion,
        ?Model $subject = null,
        ?User $creator = null,
        ?string $inputHash = null,
        array $requestMetadata = [],
        ?string $idempotencyKey = null,
    ): AiInteraction {
        $this->validateIdentifiers($inputHash, $idempotencyKey);

        $attributes = [
            'provider' => (string) (config('ai.provider') ?: 'unconfigured'),
            'model' => (string) (config('ai.model') ?: 'unconfigured'),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'prompt_version' => $featureVersion->promptVersion,
            'schema_version' => $featureVersion->schemaVersion,
            'status' => AiInteractionStatus::Pending,
            'input_hash' => $inputHash,
            'request_metadata' => $this->sanitizer->metadata($requestMetadata),
            'created_by' => $creator?->getKey(),
        ];

        if ($idempotencyKey === null) {
            return AiInteraction::create([
                'feature' => $featureVersion->feature,
                ...$attributes,
            ]);
        }

        $interaction = AiInteraction::firstOrCreate(
            [
                'feature' => $featureVersion->feature,
                'idempotency_key' => $idempotencyKey,
            ],
            $attributes,
        );

        if (! $interaction->wasRecentlyCreated && ! $this->matchesOperation($interaction, $attributes)) {
            throw new LogicException('The AI idempotency key is already associated with a different operation.');
        }

        return $interaction;
    }

    public function beginProcessing(AiInteraction $interaction): ?AiInteraction
    {
        return DB::transaction(function () use ($interaction): ?AiInteraction {
            $locked = AiInteraction::query()->lockForUpdate()->findOrFail($interaction->getKey());

            if ($locked->status === AiInteractionStatus::Succeeded) {
                return null;
            }

            if (
                $locked->status === AiInteractionStatus::Processing
                && $locked->processing_started_at?->isAfter(now()->subSeconds(self::PROCESSING_LEASE_SECONDS))
            ) {
                throw new AiInteractionInProgressException('The AI interaction is already processing.');
            }

            $locked->update([
                'status' => AiInteractionStatus::Processing,
                'processing_started_at' => now(),
                'response_payload' => null,
                'latency_ms' => null,
                'error_code' => null,
                'error_message' => null,
            ]);

            return $locked;
        });
    }

    /** @param array<string, mixed>|null $responsePayload */
    public function markSucceeded(AiInteraction $interaction, ?array $responsePayload, int $latencyMs): AiInteraction
    {
        return DB::transaction(function () use ($interaction, $responsePayload, $latencyMs): AiInteraction {
            $locked = AiInteraction::query()->lockForUpdate()->findOrFail($interaction->getKey());

            if ($locked->status !== AiInteractionStatus::Processing) {
                throw new LogicException('Only a processing AI interaction can succeed.');
            }

            $locked->update([
                'status' => AiInteractionStatus::Succeeded,
                'response_payload' => $responsePayload === null
                    ? null
                    : $this->sanitizer->metadata($responsePayload),
                'latency_ms' => max(0, $latencyMs),
                'error_code' => null,
                'error_message' => null,
            ]);

            return $locked;
        });
    }

    public function markFailed(AiInteraction $interaction, Throwable $exception, int $latencyMs): AiInteraction
    {
        return DB::transaction(function () use ($interaction, $exception, $latencyMs): AiInteraction {
            $locked = AiInteraction::query()->lockForUpdate()->findOrFail($interaction->getKey());

            if ($locked->status === AiInteractionStatus::Succeeded) {
                return $locked;
            }

            $locked->update([
                'status' => AiInteractionStatus::Failed,
                'response_payload' => null,
                'latency_ms' => max(0, $latencyMs),
                'error_code' => $this->sanitizer->errorCode($exception),
                'error_message' => $this->sanitizer->errorMessage($exception),
            ]);

            return $locked;
        });
    }

    private function validateIdentifiers(?string $inputHash, ?string $idempotencyKey): void
    {
        if ($inputHash !== null && preg_match('/^[a-f0-9]{64}$/iD', $inputHash) !== 1) {
            throw new InvalidArgumentException('The AI input hash must be a SHA-256 hexadecimal string.');
        }

        if ($idempotencyKey !== null && (trim($idempotencyKey) === '' || mb_strlen($idempotencyKey) > 100)) {
            throw new InvalidArgumentException('The AI idempotency key must contain 1 to 100 characters.');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function matchesOperation(AiInteraction $interaction, array $attributes): bool
    {
        foreach ([
            'subject_type', 'subject_id', 'prompt_version', 'schema_version', 'input_hash', 'created_by',
        ] as $attribute) {
            if ($interaction->getAttribute($attribute) !== $attributes[$attribute]) {
                return false;
            }
        }

        return true;
    }
}
