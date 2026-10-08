<?php

namespace App\Services\Tachograph;

use App\Jobs\EvaluateDriverCompliance;
use App\Jobs\FetchDriverActivitiesChunk;
use App\Models\ActivityRecord;
use App\Models\Driver;
use App\Models\ProcessingRun;
use App\Models\User;
use App\RunStatus;
use App\RunType;
use App\Tachograph\Data\Period;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;
use Throwable;

/**
 * Plans and dispatches "fetch & evaluate": one queued job per driver per ≤ 28-day chunk,
 * followed by one evaluation once every chunk succeeded.
 */
class FetchService
{
    /** Days re-fetched before the newest stored record (late driver-card downloads). */
    public const REFETCH_DAYS = 3;

    public function __construct(private readonly EvaluationService $evaluation) {}

    /**
     * @return ProcessingRun the evaluate run (or the fetch run when $evaluate is false)
     */
    public function start(Driver $driver, Period $report, ?User $user = null, bool $evaluate = true): ProcessingRun
    {
        $evaluateRun = $evaluate ? ProcessingRun::create([
            'type' => RunType::EVALUATE,
            'status' => RunStatus::PENDING,
            'driver_id' => $driver->id,
            'user_id' => $user?->id,
            'period_start' => $report->start,
            'period_end' => $report->end,
        ]) : null;

        // Local (imported) drivers have nothing to fetch.
        $window = $driver->isMapon() ? $this->fetchWindow($driver, $report) : null;

        if ($window === null) {
            if ($evaluateRun) {
                EvaluateDriverCompliance::dispatch($evaluateRun->id);
            }

            return $evaluateRun ?? throw new InvalidArgumentException('Nothing to fetch for this driver.');
        }

        $fetchRun = ProcessingRun::create([
            'type' => RunType::FETCH,
            'status' => RunStatus::PENDING,
            'driver_id' => $driver->id,
            'user_id' => $user?->id,
            'period_start' => $window->start,
            'period_end' => $window->end,
        ]);

        $jobs = array_map(
            fn (Period $chunk) => new FetchDriverActivitiesChunk($fetchRun->id, $driver->id, $chunk->start, $chunk->end),
            $this->chunks($window),
        );

        $fetchRunId = $fetchRun->id;
        $evaluateRunId = $evaluateRun?->id;

        $batch = Bus::batch($jobs)
            ->name("tacho:fetch {$fetchRunId}")
            ->then(function (Batch $batch) use ($fetchRunId, $evaluateRunId) {
                ProcessingRun::find($fetchRunId)?->markDone();

                if ($evaluateRunId) {
                    EvaluateDriverCompliance::dispatch($evaluateRunId);
                }
            })
            ->catch(function (Batch $batch, Throwable $e) use ($fetchRunId, $evaluateRunId) {
                $message = $e instanceof \App\Services\Mapon\MaponException ? $e->getMessage() : 'Fetching data from Mapon failed.';
                ProcessingRun::find($fetchRunId)?->markFailed($message);
                ProcessingRun::find($evaluateRunId)?->markFailed('Not evaluated: '.$message);
            })
            ->dispatch();

        $fetchRun->update(['batch_id' => $batch->id]);
        $evaluateRun?->update(['batch_id' => $batch->id]);

        return ($evaluateRun ?? $fetchRun)->refresh();
    }

    /**
     * Full history window when the stored data does not reach back far enough; otherwise only
     * the last few days before the newest stored record up to the end of the window.
     */
    public function fetchWindow(Driver $driver, Period $report): ?Period
    {
        $full = $this->evaluation->dataWindow($report);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $end = min($full->end, $now);

        if ($end <= $full->start) {
            return null;
        }

        $earliest = ActivityRecord::where('driver_id', $driver->id)->min('start_at');
        $latest = ActivityRecord::where('driver_id', $driver->id)->max('end_at');
        $utc = new DateTimeZone('UTC');

        if ($earliest === null || new DateTimeImmutable($earliest, $utc) > $full->start) {
            return new Period($full->start, $end);
        }

        $start = max($full->start, (new DateTimeImmutable($latest, $utc))->modify('-'.self::REFETCH_DAYS.' days'));

        return $start < $end ? new Period($start, $end) : null;
    }

    /** @return list<Period> chunks of at most fetch_chunk_days (Mapon allows 31) */
    public function chunks(Period $window): array
    {
        $days = min(31, max(1, (int) config('tachograph.fetch_chunk_days', 28)));
        $chunks = [];
        $start = $window->start;

        while ($start < $window->end) {
            $end = min($window->end, $start->modify("+{$days} days"));
            $chunks[] = new Period($start, $end);
            $start = $end;
        }

        return $chunks;
    }
}
