<?php

namespace App\Jobs;

use App\Models\Driver;
use App\Models\ProcessingRun;
use App\Models\RawPayload;
use App\Services\Mapon\MaponClient;
use App\Services\Mapon\MaponException;
use App\Services\Tachograph\ActivityStore;
use App\Tachograph\Normalization\MaponActivityNormalizer;
use App\Tachograph\Normalization\RawChunk;
use DateTimeImmutable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One driver × one ≤ 28-day chunk. Small enough for shared-hosting execution limits.
 */
class FetchDriverActivitiesChunk implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 45;

    public int $tries = 3;

    public function __construct(
        public string $processingRunId,
        public int $driverId,
        public DateTimeImmutable $from,
        public DateTimeImmutable $till,
    ) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(MaponClient $mapon, MaponActivityNormalizer $normalizer, ActivityStore $store): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $run = ProcessingRun::findOrFail($this->processingRunId);
        $driver = Driver::findOrFail($this->driverId);
        $run->markRunning();

        try {
            $payload = $mapon->getDriverDailyActivities((int) $driver->external_id, $this->from, $this->till);
        } catch (MaponException $e) {
            Log::channel('tachograph')->warning('Fetch chunk failed', [
                'processing_id' => $run->id,
                'driver' => $driver->logId(),
                'code' => $e->getCode(),
                'attempt' => $this->attempts(),
            ]);

            // Configuration / permission errors will not fix themselves: fail immediately
            // (when queued; run directly, e.g. by tacho:refresh, the caller handles it).
            if (! $e->isRetryable() && $this->job) {
                $this->fail($e);

                return;
            }

            throw $e;
        }

        $raw = RawPayload::store('driver/daily_activities', $mapon->lastBody() ?? json_encode($payload), [
            'processing_run_id' => $run->id,
            'driver_id' => $driver->id,
            'chunk_from' => $this->from,
            'chunk_till' => $this->till,
        ]);

        $result = $normalizer->normalize($driver->external_id, [new RawChunk($payload, $raw->id)]);
        $store->persist($result, $run, origin: 'mapon');
        $driver->update(['last_fetched_at' => now()]);

        Log::channel('tachograph')->info('Fetch chunk stored', [
            'processing_id' => $run->id,
            'driver' => $driver->logId(),
            'endpoint' => 'driver/daily_activities',
            'from' => $this->from->format('c'),
            'till' => $this->till->format('c'),
            'records_retrieved' => $result->recordsRetrieved,
            'records_invalid' => $result->recordsInvalid,
        ]);
    }
}
