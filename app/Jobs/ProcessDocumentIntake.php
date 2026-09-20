<?php

namespace App\Jobs;

use App\Services\DocumentExtractionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessDocumentIntake implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $documentIntakeId) {}

    /**
     * Execute the job.
     */
    public function handle(DocumentExtractionService $extraction): void
    {
        $extraction->process($this->documentIntakeId);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30];
    }

    public function uniqueId(): string
    {
        return (string) $this->documentIntakeId;
    }

    public function failed(?Throwable $exception): void
    {
        app(DocumentExtractionService::class)->markFailed($this->documentIntakeId, $exception);
    }
}
