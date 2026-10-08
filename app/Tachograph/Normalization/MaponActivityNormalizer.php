<?php

namespace App\Tachograph\Normalization;

use App\Tachograph\Config\TachoConfig;
use App\Tachograph\Data\Activity;
use App\Tachograph\Data\ActivitySource;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Data\TachoEvent;
use App\Tachograph\Data\TachoEventType;
use DateTimeImmutable;

/**
 * Converts Mapon driver/daily_activities responses into Activity DTOs and TachoEvents.
 *
 * Handles the known quirks: "day" buckets are ignored (raw timestamps only), "duration"
 * is ignored (end - start), REST with source "unkn" is a gap filler and becomes UNKNOWN,
 * zero-length card/work-period records are events, and chunk overlaps are deduplicated.
 */
final class MaponActivityNormalizer
{
    private const STATUS_MAP = [
        'DRIVING' => ActivityType::DRIVING,
        'WORK' => ActivityType::WORK,
        'AVAILABLE' => ActivityType::AVAILABILITY,
        'REST' => ActivityType::REST,
    ];

    public function __construct(private readonly TachoConfig $config) {}

    /**
     * @param  iterable<RawChunk|array>  $chunks  decoded responses (with or without the "data" wrapper)
     */
    public function normalize(string $driverId, iterable $chunks, ?NormalizationResult $result = null): NormalizationResult
    {
        $result ??= new NormalizationResult;

        if (trim($driverId) === '') {
            $result->reject(DataIssue::make(IssueType::MISSING_DRIVER_ID, 'Mapon data supplied without a driver ID.'));

            return $result;
        }

        foreach ($chunks as $chunk) {
            $chunk = $chunk instanceof RawChunk ? $chunk : new RawChunk($chunk);

            foreach ($this->days($chunk->payload) as $day) {
                foreach ($day['activities'] ?? [] as $record) {
                    $result->recordsRetrieved++;
                    $this->normalizeRecord($driverId, (array) $record, $chunk->rawPayloadId, $result);
                }
            }
        }

        $this->applyCardOut($driverId, $result);

        return $result;
    }

    private function days(array $payload): array
    {
        $days = array_key_exists('data', $payload) ? (array) $payload['data'] : $payload;

        // A single day object instead of a list.
        return isset($days['activities']) ? [$days] : array_values(array_filter($days, 'is_array'));
    }

    private function normalizeRecord(string $driverId, array $record, ?int $rawPayloadId, NormalizationResult $result): void
    {
        $status = strtoupper(trim((string) ($record['status'] ?? '')));
        $sourceValue = strtolower(trim((string) ($record['source'] ?? '')));
        $source = ActivitySource::tryFrom($sourceValue) ?? ActivitySource::UNKNOWN;
        $vehicleId = isset($record['unitId']) && $record['unitId'] !== '' ? (string) $record['unitId'] : null;
        $isEvent = TachoEventType::tryFrom($status) !== null;
        $context = ['status' => $status, 'source' => $sourceValue, 'unit_id' => $vehicleId];

        $start = $this->timestamp($record['start'] ?? null);
        $end = $this->timestamp($record['end'] ?? null) ?? ($isEvent ? $start : null);

        if ($start === false || $end === false) {
            $result->reject(DataIssue::make(IssueType::INVALID_TIMESTAMP, 'Record has an unparsable timestamp.', $driverId, context: $context));

            return;
        }

        if ($start === null || $end === null) {
            $result->reject(DataIssue::make(IssueType::MISSING_TIMESTAMP, 'Record is missing its start or end time.', $driverId, $start, $end, $context));

            return;
        }

        $key = Activity::key($driverId, $start->getTimestamp(), $end->getTimestamp(), $status, $vehicleId);

        if ($isEvent) {
            $result->addEvent(new TachoEvent($driverId, $vehicleId, TachoEventType::from($status), $start, $source, $key, $rawPayloadId));

            return;
        }

        if ($end < $start) {
            $result->reject(DataIssue::make(IssueType::END_BEFORE_START, 'Record ends before it starts.', $driverId, $start, $end, $context));

            return;
        }

        if ($end == $start) {
            $result->addIssue(DataIssue::make(IssueType::ZERO_DURATION, 'Zero-length activity ignored.', $driverId, $start, $end, $context));

            return;
        }

        $type = self::STATUS_MAP[$status] ?? null;
        $uncertain = false;

        if ($type === null) {
            $type = ActivityType::UNKNOWN;
            $uncertain = true;
            $result->addIssue(DataIssue::make(IssueType::INVALID_ACTIVITY_TYPE, "Unknown activity status \"{$status}\" treated as UNKNOWN.", $driverId, $start, $end, $context));
        } elseif ($source === ActivitySource::UNKNOWN) {
            // Mapon fills gaps with REST/unkn. Never count that as rest.
            if ($type === ActivityType::REST) {
                $type = ActivityType::UNKNOWN;
            }
            $uncertain = true;
            $result->addIssue(DataIssue::make(IssueType::UNKNOWN_SOURCE_FILL, 'Period without tachograph data (Mapon gap filler).', $driverId, $start, $end, $context));
        } elseif ($source === ActivitySource::CAN) {
            $uncertain = $this->config->canSourceUncertain;
            $result->addIssue(DataIssue::make(IssueType::NON_TACHOGRAPH_SOURCE, 'Activity from vehicle CAN data, not from the driver card.', $driverId, $start, $end, $context));
        }

        $result->addActivity(new Activity(
            driverId: $driverId,
            vehicleId: $vehicleId,
            type: $type,
            start: $start,
            end: $end,
            source: $source,
            uncertain: $uncertain,
            sourceEventIds: [$key],
            rawStatus: $status,
            rawPayloadId: $rawPayloadId,
        ));
    }

    /** @return DateTimeImmutable|null|false null when missing, false when unparsable */
    private function timestamp(mixed $value): DateTimeImmutable|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value != $value || (int) $value < 0) {
            return false;
        }

        return new DateTimeImmutable('@'.(int) $value);
    }

    /**
     * REST between CARD_REMOVED and the next CARD_INSERTED was not recorded on the card.
     */
    private function applyCardOut(string $driverId, NormalizationResult $result): void
    {
        $policy = $this->config->cardOutRestAs;
        $windows = [];
        $removedAt = null;

        foreach ($result->events($driverId) as $event) {
            if ($event->type === TachoEventType::CARD_REMOVED) {
                $removedAt ??= $event->occurredAt;
            } elseif ($event->type === TachoEventType::CARD_INSERTED && $removedAt !== null) {
                $windows[] = [$removedAt, $event->occurredAt];
                $removedAt = null;
            }
        }

        if ($policy === 'rest' || $windows === []) {
            return;
        }

        $result->mapActivities($driverId, function (Activity $activity) use ($windows, $policy, $result) {
            if ($activity->type !== ActivityType::REST) {
                return $activity;
            }

            foreach ($windows as [$from, $till]) {
                if ($activity->start >= $from && $activity->end <= $till) {
                    $result->addIssue(DataIssue::make(IssueType::CARD_OUT_REST, 'Rest taken with the driver card removed.', $activity->driverId, $activity->start, $activity->end));

                    return $policy === 'unknown'
                        ? $activity->with(['type' => ActivityType::UNKNOWN, 'uncertain' => true])
                        : $activity->with(['uncertain' => true]);
                }
            }

            return $activity;
        });
    }
}
