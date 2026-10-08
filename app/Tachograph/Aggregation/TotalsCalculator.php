<?php

namespace App\Tachograph\Aggregation;

use App\Tachograph\Compliance\RuleContext;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Data\Period;
use App\Tachograph\Periods\Shift;

/**
 * Seconds per activity group for a period, a shift or a week. Rest-like types are reported
 * as "break" (BREAK) and "rest" (REST, DAILY_REST, WEEKLY_REST).
 */
final class TotalsCalculator
{
    public const GROUPS = ['driving', 'work', 'availability', 'break', 'rest', 'unknown'];

    /** @return array<string, int> */
    public function forPeriod(RuleContext $context, Period $period): array
    {
        $span = $context->untilHorizon($period);

        return $span ? self::group($context->timeline->totals($span)) : array_fill_keys(self::GROUPS, 0);
    }

    /** @return array<string, int> */
    public function forShift(Shift $shift): array
    {
        $totals = array_fill_keys(array_map(fn (ActivityType $t) => $t->value, ActivityType::cases()), 0);

        foreach ($shift->activities as $activity) {
            $totals[$activity->type->value] += $activity->durationSeconds();
        }

        return self::group($totals);
    }

    /** @param array<string, int> $byType keyed by ActivityType value */
    private static function group(array $byType): array
    {
        return [
            'driving' => $byType['DRIVING'],
            'work' => $byType['WORK'],
            'availability' => $byType['AVAILABILITY'],
            'break' => $byType['BREAK'],
            'rest' => $byType['REST'] + $byType['DAILY_REST'] + $byType['WEEKLY_REST'],
            'unknown' => $byType['UNKNOWN'],
        ];
    }
}
