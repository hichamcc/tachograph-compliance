<?php

namespace App\Services\Tachograph;

use App\Models\ActivityRecord;
use App\Models\Driver;
use App\Models\ProcessingRun;
use App\Models\TachoEventRecord;
use App\Models\ValidationIssue;
use App\Models\Vehicle;
use App\RunType;
use App\Tachograph\Data\Activity;
use App\Tachograph\Data\ActivitySource;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Data\Period;
use App\Tachograph\Normalization\DataIssue;
use App\Tachograph\Normalization\DataValidator;
use App\Tachograph\Normalization\NormalizationResult;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Bridges the plain-PHP domain layer and the database: persists normalized activities,
 * events and data-quality issues, and loads activities back as DTOs.
 */
class ActivityStore
{
    private const DB_FORMAT = 'Y-m-d H:i:s';

    /** @var array<string, int> vehicle external ID → id */
    private array $vehicleIds = [];

    public function __construct(private readonly DataValidator $validator) {}

    /**
     * Upserts activities and events (idempotent on driver + source event key) and records issues.
     *
     * @param  string  $origin  origin for drivers created by this call (mapon | local)
     * @param  bool  $replace  the result is the complete, current version of the period it covers
     *                         (a Mapon download): stored records starting in that period that are not
     *                         in the result are stale and removed, with their data-problem notes
     * @return list<Driver> drivers touched
     */
    public function persist(NormalizationResult $result, ProcessingRun $run, string $origin = 'mapon', bool $replace = false): array
    {
        $drivers = [];

        DB::transaction(function () use ($result, $run, $origin, $replace, &$drivers) {
            foreach ($result->driverIds() as $externalId) {
                $driver = Driver::firstOrCreate(['external_id' => $externalId], ['origin' => $origin]);
                $activities = $result->activities($externalId);

                if ($replace) {
                    $this->removeStale($driver, $result, $run);
                }

                $this->upsertActivities($driver, $activities, $run);
                $this->upsertEvents($driver, $result, $run);
                $this->storeIssues($run, $driver, [...$result->issues($externalId), ...$this->validator->validate($activities)]);
                $driver->refreshLastActive();

                $drivers[] = $driver;
            }

            // Issues not attributable to a driver (e.g. MISSING_DRIVER_ID).
            $orphans = array_filter($result->issues(), fn (DataIssue $i) => $i->driverId === null || ! in_array($i->driverId, $result->driverIds(), true));
            $this->storeIssues($run, null, $orphans);

            $run->increment('records_retrieved', $result->recordsRetrieved);
            $run->increment('records_processed', $result->activityCount());
            $run->increment('records_invalid', $result->recordsInvalid);
        });

        return $drivers;
    }

    /**
     * Activities overlapping the period (not clipped), sorted by start.
     *
     * @return list<Activity>
     */
    public function load(Driver $driver, Period $period): array
    {
        $utc = new DateTimeZone('UTC');

        return ActivityRecord::query()
            ->with('vehicle:id,external_id')
            ->where('driver_id', $driver->id)
            ->where('start_at', '<', $period->end->format(self::DB_FORMAT))
            ->where('end_at', '>', $period->start->format(self::DB_FORMAT))
            ->orderBy('start_at')
            ->get()
            ->map(fn (ActivityRecord $r) => new Activity(
                driverId: $driver->external_id,
                vehicleId: $r->vehicle?->external_id,
                type: ActivityType::from($r->type),
                start: new DateTimeImmutable($r->getRawOriginal('start_at'), $utc),
                end: new DateTimeImmutable($r->getRawOriginal('end_at'), $utc),
                source: ActivitySource::from($r->source),
                uncertain: $r->is_uncertain,
                sourceEventIds: [$r->source_event_key],
                rawStatus: $r->raw_status,
                rawPayloadId: $r->raw_payload_id,
            ))
            ->all();
    }

    /**
     * Mapon re-cuts recent days as data arrives (gap fillers shrink, records are re-split), so a
     * re-download is the new truth for the span it covers.
     */
    private function removeStale(Driver $driver, NormalizationResult $result, ProcessingRun $run): void
    {
        $activities = $result->activities($driver->external_id);
        $events = $result->events($driver->external_id);
        $times = [
            ...array_map(fn (Activity $a) => [$a->start, $a->end], $activities),
            ...array_map(fn ($e) => [$e->occurredAt, $e->occurredAt], $events),
        ];

        if ($times === []) {
            return; // nothing returned: keep what we have
        }

        $from = self::format(min(array_column($times, 0)));
        $till = self::format(max(array_column($times, 1)));
        $keys = array_map(fn (Activity $a) => $a->sourceEventIds[0], $activities);
        $eventKeys = array_map(fn ($e) => $e->sourceEventId, $events);

        ActivityRecord::where('driver_id', $driver->id)
            ->where('start_at', '>=', $from)->where('start_at', '<', $till)
            ->whereNotIn('source_event_key', $keys)
            ->delete();

        TachoEventRecord::where('driver_id', $driver->id)
            ->where('occurred_at', '>=', $from)->where('occurred_at', '<=', $till)
            ->whereNotIn('source_event_key', $eventKeys)
            ->delete();

        // Notes recorded by earlier downloads/imports for this span are recomputed now.
        ValidationIssue::where('driver_id', $driver->id)
            ->where('processing_run_id', '!=', $run->id)
            ->whereHas('processingRun', fn ($q) => $q->where('type', '!=', RunType::EVALUATE->value))
            ->where('period_start', '>=', $from)->where('period_start', '<', $till)
            ->delete();
    }

    /** @param list<Activity> $activities */
    private function upsertActivities(Driver $driver, array $activities, ProcessingRun $run): void
    {
        $now = now()->format(self::DB_FORMAT);

        $rows = array_map(fn (Activity $a) => [
            'driver_id' => $driver->id,
            'vehicle_id' => $this->vehicleId($a->vehicleId),
            'type' => $a->type->value,
            'raw_status' => $a->rawStatus,
            'source' => $a->source->value,
            'start_at' => self::format($a->start),
            'end_at' => self::format($a->end),
            'duration_seconds' => $a->durationSeconds(),
            'is_uncertain' => $a->uncertain,
            'source_event_key' => $a->sourceEventIds[0],
            'raw_payload_id' => $a->rawPayloadId,
            'processing_run_id' => $run->id,
            'created_at' => $now,
            'updated_at' => $now,
        ], $activities);

        foreach (array_chunk($rows, 500) as $chunk) {
            ActivityRecord::upsert(
                $chunk,
                ['driver_id', 'source_event_key'],
                ['vehicle_id', 'type', 'raw_status', 'source', 'is_uncertain', 'raw_payload_id', 'processing_run_id', 'updated_at'],
            );
        }
    }

    private function upsertEvents(Driver $driver, NormalizationResult $result, ProcessingRun $run): void
    {
        $now = now()->format(self::DB_FORMAT);

        $rows = array_map(fn ($e) => [
            'driver_id' => $driver->id,
            'vehicle_id' => $this->vehicleId($e->vehicleId),
            'type' => $e->type->value,
            'occurred_at' => self::format($e->occurredAt),
            'source' => $e->source->value,
            'source_event_key' => $e->sourceEventId,
            'raw_payload_id' => $e->rawPayloadId,
            'processing_run_id' => $run->id,
            'created_at' => $now,
            'updated_at' => $now,
        ], $result->events($driver->external_id));

        foreach (array_chunk($rows, 500) as $chunk) {
            TachoEventRecord::upsert($chunk, ['driver_id', 'source_event_key'], ['raw_payload_id', 'processing_run_id', 'updated_at']);
        }
    }

    /** @param iterable<DataIssue> $issues */
    private function storeIssues(ProcessingRun $run, ?Driver $driver, iterable $issues): void
    {
        $now = now()->format(self::DB_FORMAT);
        $rows = [];

        foreach ($issues as $issue) {
            $rows[] = [
                'processing_run_id' => $run->id,
                'driver_id' => $driver?->id,
                'severity' => $issue->severity->value,
                'type' => $issue->type->value,
                'period_start' => $issue->periodStart ? self::format($issue->periodStart) : null,
                'period_end' => $issue->periodEnd ? self::format($issue->periodEnd) : null,
                'message' => $issue->message,
                'context' => $issue->context ? json_encode($issue->context) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            ValidationIssue::insert($chunk);
        }
    }

    private function vehicleId(?string $externalId): ?int
    {
        if ($externalId === null) {
            return null;
        }

        return $this->vehicleIds[$externalId] ??= Vehicle::firstOrCreate(['external_id' => $externalId])->id;
    }

    private static function format(DateTimeInterface $time): string
    {
        return DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC'))->format(self::DB_FORMAT);
    }
}
