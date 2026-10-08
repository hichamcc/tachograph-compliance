<?php

namespace App\Tachograph\Rules;

use App\Tachograph\Compliance\FindingStatus;
use App\Tachograph\Compliance\RuleContext;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Data\Period;

/**
 * Art. 6(3): driving in any two consecutive fixed weeks <= 90h. Time of the previous week
 * not covered by data counts as unknown.
 */
final class TwoWeekDrivingRule extends AbstractRule
{
    public const CODE = 'TWO_WEEK_DRIVING_LIMIT';

    public function codes(): array
    {
        return [self::CODE];
    }

    public function evaluate(RuleContext $context): array
    {
        $max = $context->config->hours('max_two_week_driving_hours');
        $findings = [];

        foreach ($context->reportWeeks() as $week) {
            $previous = $context->calendar->previous($week);
            $span = $context->untilHorizon(new Period($previous->start, $week->end));

            if ($span === null) {
                continue;
            }

            $totals = $context->timeline->totals($span);
            $driving = $totals[ActivityType::DRIVING->value];
            $unknown = $totals[ActivityType::UNKNOWN->value];
            $label = $context->calendar->label($previous).' + '.$context->calendar->label($week);
            $previousDriving = $context->timeline->secondsOf(ActivityType::DRIVING, $previous);

            $status = match (true) {
                $driving > $max => FindingStatus::VIOLATION,
                $driving + $unknown > $max => FindingStatus::INCOMPLETE_DATA,
                default => FindingStatus::COMPLIANT,
            };

            $findings[] = $this->finding(
                $context, self::CODE, $status, $previous->start, $week->end,
                self::hours($driving), self::hours($max), 'hours',
                match ($status) {
                    FindingStatus::VIOLATION => sprintf('Driving %s in %s exceeds %s.', self::hm($driving), $label, self::hm($max)),
                    FindingStatus::INCOMPLETE_DATA => sprintf('Driving %s in %s; %s of unknown data could exceed %s.', self::hm($driving), $label, self::hm($unknown), self::hm($max)),
                    default => sprintf('Driving %s in %s.', self::hm($driving), $label),
                },
                $context->activitiesOf(ActivityType::DRIVING, $span),
                [
                    'weeks' => $label,
                    'previous_week_hours' => self::hours($previousDriving),
                    'previous_week_covered' => $context->coverage()->start <= $previous->start,
                    'unknown_hours' => self::hours($unknown),
                ],
            );
        }

        return $findings;
    }
}
