<?php

namespace App\Tachograph\Rules;

use App\Tachograph\Compliance\FindingStatus;
use App\Tachograph\Compliance\RuleContext;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Periods\Shift;

/**
 * Art. 6(1): daily driving (per shift, not calendar day) <= 9h, extendable to 10h at most
 * twice per fixed week. A shift belongs to the week in which it starts.
 */
final class DailyDrivingRule extends AbstractRule
{
    public const CODE = 'DAILY_DRIVING_LIMIT';

    public const EXTENDED_CODE = 'EXTENDED_DAYS_PER_WEEK';

    public function codes(): array
    {
        return [self::CODE, self::EXTENDED_CODE];
    }

    public function evaluate(RuleContext $context): array
    {
        $config = $context->config;
        $max = $config->hours('max_daily_driving_hours');
        $extended = $config->hours('extended_daily_driving_hours');
        $splitsShift = $config->hours('reduced_daily_rest_minimum_hours');

        $findings = [];
        $weeks = []; // week start ts => ['extended' => list<Shift>, 'possible' => int]

        foreach ($context->shifts() as $shift) {
            $driving = $shift->drivingSeconds();
            $unknown = $shift->secondsOf(ActivityType::UNKNOWN);
            $isExtended = $driving > $max;

            $status = match (true) {
                // An UNKNOWN block long enough to be a daily rest may split the shift in two.
                $driving > $extended => $shift->longestUnknownSeconds() >= $splitsShift ? FindingStatus::INCOMPLETE_DATA : FindingStatus::VIOLATION,
                $driving + $unknown > $extended => FindingStatus::INCOMPLETE_DATA,
                ! $shift->startKnown() => FindingStatus::INCOMPLETE_DATA,
                $isExtended => FindingStatus::WARNING,
                default => FindingStatus::COMPLIANT,
            };

            $weekKey = $context->calendar->weekOf($shift->start)->start->getTimestamp();
            $weeks[$weekKey] ??= ['extended' => [], 'possible' => 0];

            if ($isExtended) {
                $weeks[$weekKey]['extended'][] = $shift;
            } elseif ($driving + $unknown > $max) {
                $weeks[$weekKey]['possible']++;
            }

            if (! $context->inReport($shift->start)) {
                continue;
            }

            $message = match ($status) {
                FindingStatus::COMPLIANT => sprintf('%s driving in shift.', self::hm($driving)),
                FindingStatus::WARNING => sprintf('Extended driving day: %s (max %s).', self::hm($driving), self::hm($extended)),
                FindingStatus::VIOLATION => sprintf('%s driving in shift exceeds %s.', self::hm($driving), self::hm($extended)),
                default => $shift->startKnown()
                    ? sprintf('%s driving; %s of unknown data in shift.', self::hm($driving), self::hm($unknown))
                    : sprintf('%s driving; shift began before the available data.', self::hm($driving)),
            };

            $findings[] = $this->finding(
                $context, self::CODE, $status, $shift->start, $shift->end,
                self::hours($driving), self::hours($isExtended ? $extended : $max), 'hours', $message,
                $shift->activitiesOf(ActivityType::DRIVING),
                ['extended' => $isExtended, 'limit_hours' => self::hours($isExtended ? $extended : $max), 'unknown_hours' => self::hours($unknown)],
            );
        }

        return [...$findings, ...$this->weeklyExtensions($context, $weeks)];
    }

    private function weeklyExtensions(RuleContext $context, array $weeks): array
    {
        $allowed = $context->config->count('maximum_extended_daily_driving_days_per_week');
        $findings = [];

        foreach ($context->reportWeeks() as $week) {
            $data = $weeks[$week->start->getTimestamp()] ?? ['extended' => [], 'possible' => 0];
            $used = count($data['extended']);

            $status = match (true) {
                $used > $allowed => FindingStatus::VIOLATION,
                $used + $data['possible'] > $allowed => FindingStatus::INCOMPLETE_DATA,
                default => FindingStatus::COMPLIANT,
            };

            $evidence = array_merge([], ...array_map(fn (Shift $s) => $s->activitiesOf(ActivityType::DRIVING), $data['extended']));

            $findings[] = $this->finding(
                $context, self::EXTENDED_CODE, $status, $week->start, $week->end,
                $used, $allowed, 'count',
                $status === FindingStatus::VIOLATION
                    ? sprintf('%d extended driving days in week %s (max %d).', $used, $context->calendar->label($week), $allowed)
                    : sprintf('%d of %d extended driving days used in week %s.', $used, $allowed, $context->calendar->label($week)),
                $evidence,
                ['week' => $context->calendar->label($week), 'used' => $used, 'remaining' => max(0, $allowed - $used)],
            );
        }

        return $findings;
    }
}
