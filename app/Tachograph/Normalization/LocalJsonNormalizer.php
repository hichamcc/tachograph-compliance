<?php

namespace App\Tachograph\Normalization;

use App\Tachograph\Config\TachoConfig;
use App\Tachograph\Data\Activity;
use App\Tachograph\Data\ActivitySource;
use App\Tachograph\Data\ActivityType;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Converts the local fixture format into Activity DTOs. Accepted shapes:
 *
 *   [{"driver_id": "...", "vehicle_id": "...", "activity_type": "DRIVING",
 *     "start_time": "2026-09-28T08:00:00Z", "end_time": "..."}]
 *   {"driver_id": "...", "activities": [ ...records, driver_id optional... ]}
 *
 * Optional per-record fields: "source" (ddd|can|unkn|local), "uncertain" (bool).
 * Timestamps must carry an explicit offset; non-UTC offsets are converted and flagged.
 */
final class LocalJsonNormalizer
{
    private const TYPE_MAP = [
        'DRIVING' => ActivityType::DRIVING,
        'WORK' => ActivityType::WORK,
        'AVAILABILITY' => ActivityType::AVAILABILITY,
        'AVAILABLE' => ActivityType::AVAILABILITY,
        // Classification of rest is our job: all rest-like inputs become REST.
        'REST' => ActivityType::REST,
        'BREAK' => ActivityType::REST,
        'DAILY_REST' => ActivityType::REST,
        'WEEKLY_REST' => ActivityType::REST,
        'UNKNOWN' => ActivityType::UNKNOWN,
    ];

    public function __construct(private readonly TachoConfig $config) {}

    public function normalize(array $payload, ?NormalizationResult $result = null): NormalizationResult
    {
        $result ??= new NormalizationResult;
        $defaultDriver = isset($payload['driver_id']) ? (string) $payload['driver_id'] : null;
        $records = $payload['activities'] ?? (array_is_list($payload) ? $payload : []);

        foreach ($records as $index => $record) {
            $result->recordsRetrieved++;
            $this->normalizeRecord((array) $record, $index, $defaultDriver, $result);
        }

        return $result;
    }

    private function normalizeRecord(array $record, int $index, ?string $defaultDriver, NormalizationResult $result): void
    {
        $driverId = trim((string) ($record['driver_id'] ?? $defaultDriver ?? ''));
        $vehicleId = isset($record['vehicle_id']) && $record['vehicle_id'] !== '' ? (string) $record['vehicle_id'] : null;
        $rawType = strtoupper(trim((string) ($record['activity_type'] ?? '')));
        $context = ['record' => $index, 'activity_type' => $rawType];

        if ($driverId === '') {
            $result->reject(DataIssue::make(IssueType::MISSING_DRIVER_ID, "Record #{$index} has no driver_id.", context: $context));

            return;
        }

        $start = $this->parseTime($record['start_time'] ?? null, 'start_time', $driverId, $context, $result);
        $end = $this->parseTime($record['end_time'] ?? null, 'end_time', $driverId, $context, $result);

        if ($start === false || $end === false) {
            $result->recordsInvalid++;

            return;
        }

        if ($start === null || $end === null) {
            $result->reject(DataIssue::make(IssueType::MISSING_TIMESTAMP, "Record #{$index} is missing start_time or end_time.", $driverId, $start, $end, $context));

            return;
        }

        if ($end < $start) {
            $result->reject(DataIssue::make(IssueType::END_BEFORE_START, "Record #{$index} ends before it starts.", $driverId, $start, $end, $context));

            return;
        }

        if ($end == $start) {
            $result->addIssue(DataIssue::make(IssueType::ZERO_DURATION, "Record #{$index} has zero duration and was ignored.", $driverId, $start, $end, $context));

            return;
        }

        $source = ActivitySource::tryFrom(strtolower((string) ($record['source'] ?? ''))) ?? ActivitySource::LOCAL;
        $uncertain = (bool) ($record['uncertain'] ?? false);
        $type = self::TYPE_MAP[$rawType] ?? null;

        if ($type === null) {
            $type = ActivityType::UNKNOWN;
            $result->addIssue(DataIssue::make(IssueType::INVALID_ACTIVITY_TYPE, "Record #{$index} has unknown activity_type \"{$rawType}\"; treated as UNKNOWN.", $driverId, $start, $end, $context));
        }

        if ($source === ActivitySource::UNKNOWN && $type === ActivityType::REST) {
            $type = ActivityType::UNKNOWN;
            $result->addIssue(DataIssue::make(IssueType::UNKNOWN_SOURCE_FILL, "Record #{$index} is a gap filler; not counted as rest.", $driverId, $start, $end, $context));
        }

        if ($source === ActivitySource::CAN) {
            $uncertain = $uncertain || $this->config->canSourceUncertain;
            $result->addIssue(DataIssue::make(IssueType::NON_TACHOGRAPH_SOURCE, "Record #{$index} comes from vehicle CAN data.", $driverId, $start, $end, $context));
        }

        $uncertain = $uncertain || $type === ActivityType::UNKNOWN || $source === ActivitySource::UNKNOWN;

        $result->addActivity(new Activity(
            driverId: $driverId,
            vehicleId: $vehicleId,
            type: $type,
            start: $start,
            end: $end,
            source: $source,
            uncertain: $uncertain,
            sourceEventIds: [Activity::key($driverId, $start->getTimestamp(), $end->getTimestamp(), $rawType, $vehicleId)],
            rawStatus: $rawType,
        ));
    }

    /** @return DateTimeImmutable|null|false null when missing, false when rejected (issue already recorded) */
    private function parseTime(mixed $value, string $field, string $driverId, array $context, NormalizationResult $result): DateTimeImmutable|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/i', $value, $m)) {
            $result->addIssue(DataIssue::make(IssueType::INVALID_TIMESTAMP, "Unparsable {$field} \"{$value}\".", $driverId, context: $context));

            return false;
        }

        if (empty($m[3])) {
            $result->addIssue(DataIssue::make(IssueType::TIMEZONE_INCONSISTENCY, "{$field} \"{$value}\" has no timezone offset; expected UTC (Z).", $driverId, context: $context));

            return false;
        }

        try {
            $time = new DateTimeImmutable($value);
        } catch (Exception) {
            $result->addIssue(DataIssue::make(IssueType::INVALID_TIMESTAMP, "Unparsable {$field} \"{$value}\".", $driverId, context: $context));

            return false;
        }

        $warnings = DateTimeImmutable::getLastErrors();

        if ($warnings !== false && ($warnings['warning_count'] > 0 || $warnings['error_count'] > 0)) {
            $result->addIssue(DataIssue::make(IssueType::INVALID_TIMESTAMP, "Invalid date in {$field} \"{$value}\".", $driverId, context: $context));

            return false;
        }

        if ($time->getOffset() !== 0) {
            $result->addIssue(DataIssue::make(
                IssueType::TIMEZONE_INCONSISTENCY,
                "{$field} \"{$value}\" is not UTC; converted.",
                $driverId,
                context: $context,
                severity: IssueSeverity::WARNING,
            ));
        }

        return $time->setTimezone(new DateTimeZone('UTC'));
    }
}
