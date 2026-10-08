<?php

namespace App\Services\Tachograph;

use App\Models\ProcessingRun;
use App\Models\RawPayload;
use App\Models\User;
use App\RunStatus;
use App\RunType;
use App\Tachograph\Data\Activity;
use App\Tachograph\Normalization\LocalJsonNormalizer;
use App\Tachograph\Normalization\MaponActivityNormalizer;
use App\Tachograph\Normalization\RawChunk;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Imports a local JSON file (fixture format or raw Mapon daily_activities response).
 */
class ImportService
{
    public function __construct(
        private readonly ActivityStore $store,
        private readonly LocalJsonNormalizer $local,
        private readonly MaponActivityNormalizer $mapon,
    ) {}

    /**
     * @param  string  $format  local | mapon
     * @param  string|null  $driverId  required for the mapon format
     *
     * @throws InvalidArgumentException for invalid input (nothing is stored)
     */
    public function import(string $contents, string $format, ?string $driverId = null, ?User $user = null): ProcessingRun
    {
        if (! in_array($format, ['local', 'mapon'], true)) {
            throw new InvalidArgumentException('Format must be "local" or "mapon".');
        }

        if ($format === 'mapon' && ! $driverId) {
            throw new InvalidArgumentException('A driver ID is required for the Mapon format.');
        }

        try {
            $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Invalid JSON: '.$e->getMessage());
        }

        if (! is_array($payload)) {
            throw new InvalidArgumentException('Invalid JSON: expected an object or a list.');
        }

        $run = ProcessingRun::create([
            'type' => RunType::IMPORT,
            'status' => RunStatus::RUNNING,
            'user_id' => $user?->id,
            'started_at' => now(),
        ]);

        try {
            $raw = RawPayload::store('import/'.$format, $contents, ['processing_run_id' => $run->id]);

            $result = $format === 'mapon'
                ? $this->mapon->normalize($driverId, [new RawChunk($payload, $raw->id)])
                : $this->local->normalize($payload);

            $drivers = $this->store->persist($result, $run, origin: 'local');
            $activities = $result->activities();

            $run->update([
                'driver_id' => count($drivers) === 1 ? $drivers[0]->id : null,
                'period_start' => $activities ? min(array_map(fn (Activity $a) => $a->start, $activities)) : null,
                'period_end' => $activities ? max(array_map(fn (Activity $a) => $a->end, $activities)) : null,
            ]);
            $run->markDone();
        } catch (Throwable $e) {
            $run->markFailed(class_basename($e).': import failed.');

            throw $e;
        }

        $run->refresh();

        Log::channel('tachograph')->info('Import finished', [
            'processing_id' => $run->id,
            'format' => $format,
            'drivers' => array_map(fn ($d) => $d->logId(), $drivers),
            'records_retrieved' => $run->records_retrieved,
            'records_processed' => $run->records_processed,
            'records_invalid' => $run->records_invalid,
        ]);

        return $run;
    }
}
