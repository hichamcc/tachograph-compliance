<?php

namespace App\Jobs;

use App\Models\ProcessingRun;
use App\Services\Tachograph\EvaluationService;
use App\Tachograph\Data\Period;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EvaluateDriverCompliance implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public string $processingRunId) {}

    public function handle(EvaluationService $evaluation): void
    {
        $run = ProcessingRun::with('driver')->findOrFail($this->processingRunId);
        $utc = new DateTimeZone('UTC');

        $period = new Period(
            new DateTimeImmutable($run->getRawOriginal('period_start'), $utc),
            new DateTimeImmutable($run->getRawOriginal('period_end'), $utc),
        );

        $evaluation->evaluate($run->driver, $period, run: $run);
    }

    public function failed(?\Throwable $e): void
    {
        $run = ProcessingRun::find($this->processingRunId);

        if ($run && ! $run->status->isFinished()) {
            $run->markFailed('Evaluation failed.');
        }
    }
}
