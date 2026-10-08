<?php

namespace App\Tachograph\Rules;

use App\Tachograph\Compliance\FindingStatus;
use App\Tachograph\Compliance\RuleContext;
use App\Tachograph\Data\ActivityType;

/**
 * Art. 6(2): driving within a fixed week <= 56h. Activities crossing the week boundary are split.
 */
final class WeeklyDrivingRule extends AbstractRule
{
    public const CODE = 'WEEKLY_DRIVING_LIMIT';

    public function codes(): array
    {
        return [self::CODE];
    }

    public function evaluate(RuleContext $context): array
    {
        $max = $context->config->hours('max_weekly_driving_hours');
        $findings = [];

        foreach ($context->reportWeeks() as $week) {
            $span = $context->untilHorizon($week);

            if ($span === null) {
                continue;
            }

            $totals = $context->timeline->totals($span);
            $driving = $totals[ActivityType::DRIVING->value];
            $unknown = $totals[ActivityType::UNKNOWN->value];
            $label = $context->calendar->label($week);

            $status = match (true) {
                $driving > $max => FindingStatus::VIOLATION,
                $driving + $unknown > $max => FindingStatus::INCOMPLETE_DATA,
                default => FindingStatus::COMPLIANT,
            };

            $findings[] = $this->finding(
                $context, self::CODE, $status, $week->start, $week->end,
                self::hours($driving), self::hours($max), 'hours',
                match ($status) {
                    FindingStatus::VIOLATION => sprintf('Weekly driving %s in %s exceeds %s.', self::hm($driving), $label, self::hm($max)),
                    FindingStatus::INCOMPLETE_DATA => sprintf('Weekly driving %s in %s; %s of unknown data could exceed %s.', self::hm($driving), $label, self::hm($unknown), self::hm($max)),
                    default => sprintf('Weekly driving %s in %s.', self::hm($driving), $label),
                },
                $context->activitiesOf(ActivityType::DRIVING, $span),
                ['week' => $label, 'week_complete' => $span->end == $week->end, 'unknown_hours' => self::hours($unknown)],
            );
        }

        return $findings;
    }
}
