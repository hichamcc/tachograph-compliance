<?php

namespace App\Tachograph\Reporting;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Rows for the day-by-day activity timeline: one row per local day of the report,
 * with segment positions as percentages of that day (23/25h on DST days).
 */
final class DayChart
{
    /** Activity type → chart category, in fixed legend/colour order. */
    public const CATEGORIES = ['drive' => 'Driving', 'work' => 'Work', 'available' => 'Availability', 'rest' => 'Rest / break', 'none' => 'No data'];

    /** Shift-level violations are drawn on the timeline; weekly ones stay in the table. */
    private const MARKED_RULES = ['BREAK_AFTER_4_5_HOURS', 'DAILY_DRIVING_LIMIT', 'DAILY_REST'];

    /** @return list<array{date: string, iso: string, segments: list<array>, markers: list<array>, driving_hours: float}> */
    public static function rows(ReportData $report): array
    {
        $tz = new DateTimeZone($report->displayTimezone);
        $periodStart = new DateTimeImmutable($report->period['start']);
        $periodEnd = new DateTimeImmutable($report->period['end']);

        $violations = array_filter(
            $report->findingsWithStatus('VIOLATION'),
            fn (array $f) => in_array($f['rule'], self::MARKED_RULES, true),
        );

        // One row per report day (the report's dates), drawn as local days.
        $rows = [];
        $day = new DateTimeImmutable($periodStart->format('Y-m-d'), $tz);
        $last = new DateTimeImmutable($periodEnd->modify('-1 second')->format('Y-m-d'), $tz);
        $lastWithData = $periodEnd->modify('-1 second')->setTimezone($tz)->setTime(0, 0);

        while ($day <= max($last, $lastWithData)) {
            $next = $day->modify('+1 day');
            $row = self::row($report, $day, $next, $violations);

            // The report runs in UTC: a local day after the last report day only holds the
            // few hours of offset. Show it only when something other than rest happened then.
            $active = array_filter($row['segments'], fn (array $s) => ! in_array($s['category'], ['rest', 'none'], true));
            if ($day <= $last || $active) {
                $rows[] = $row;
            }

            $day = $next;
        }

        return $rows;
    }

    private static function row(ReportData $report, DateTimeImmutable $from, DateTimeImmutable $till, array $violations): array
    {
        $length = $till->getTimestamp() - $from->getTimestamp();
        $segments = [];
        $driving = 0;

        foreach ($report->activities as $activity) {
            $clip = self::clip($activity['start'], $activity['end'], $from, $till);

            if ($clip === null) {
                continue;
            }

            [$start, $end] = $clip;
            $seconds = $end - $start;
            $category = self::category($activity['type']);

            if ($category === 'drive') {
                $driving += $seconds;
            }

            $segments[] = [
                'category' => $category,
                'label' => self::CATEGORIES[$category],
                'left' => round(($start - $from->getTimestamp()) / $length * 100, 3),
                'width' => round($seconds / $length * 100, 3),
                'start' => gmdate('Y-m-d\TH:i:s\Z', $start),
                'end' => gmdate('Y-m-d\TH:i:s\Z', $end),
                'hours' => round($seconds / 3600, 2),
                'source' => $activity['source'],
            ];
        }

        $markers = [];
        foreach ($violations as $finding) {
            if ($clip = self::clip($finding['period_start'], $finding['period_end'], $from, $till)) {
                [$start, $end] = $clip;
                $markers[] = [
                    'left' => round(($start - $from->getTimestamp()) / $length * 100, 3),
                    'width' => round(($end - $start) / $length * 100, 3),
                    'potential' => $finding['certainty'] === 'POTENTIAL',
                    'rule' => Format::rule($finding['rule']),
                    'value' => Format::value($finding),
                    'start' => $finding['period_start'],
                    'end' => $finding['period_end'],
                ];
            }
        }

        return [
            'date' => $from->format('D j M'),
            'iso' => $from->format('Y-m-d'),
            'segments' => $segments,
            'markers' => $markers,
            'driving_hours' => round($driving / 3600, 2),
        ];
    }

    /** @return array{int, int}|null clipped Unix range */
    private static function clip(string $startIso, string $endIso, DateTimeImmutable $from, DateTimeImmutable $till): ?array
    {
        $start = max((new DateTimeImmutable($startIso))->getTimestamp(), $from->getTimestamp());
        $end = min((new DateTimeImmutable($endIso))->getTimestamp(), $till->getTimestamp());

        return $end > $start ? [$start, $end] : null;
    }

    private static function category(string $type): string
    {
        return match ($type) {
            'DRIVING' => 'drive',
            'WORK' => 'work',
            'AVAILABILITY' => 'available',
            'UNKNOWN' => 'none',
            default => 'rest',
        };
    }
}
