<?php

namespace App\Jobs;

use App\AI\AiRequest;
use App\AI\Contracts\StructuredAiSchema;
use App\Models\AiInteraction;
use App\Services\AiExecutionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecuteAiInteraction implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $interactionId,
        public readonly AiRequest $request,
        public readonly ?StructuredAiSchema $schema = null,
    ) {}

    public function handle(AiExecutionService $execution): void
    {
        $execution->execute(
            AiInteraction::query()->findOrFail($this->interactionId),
            $this->request,
            $this->schema,
        );
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30];
    }

    public function uniqueId(): string
    {
        return (string) $this->interactionId;
    }
}
