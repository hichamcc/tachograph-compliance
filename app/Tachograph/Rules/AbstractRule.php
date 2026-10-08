<?php

namespace App\Tachograph\Rules;

use App\Tachograph\Compliance\Certainty;
use App\Tachograph\Compliance\Finding;
use App\Tachograph\Compliance\FindingStatus;
use App\Tachograph\Compliance\RuleContext;
use App\Tachograph\Compliance\Severity;
use App\Tachograph\Data\Activity;
use App\Tachograph\Data\ActivityType;
use DateTimeImmutable;

abstract class AbstractRule implements Rule
{
    /**
     * @param  list<Activity>  $evidence  activities the outcome depends on
     */
    protected function finding(
        RuleContext $context,
        string $rule,
        FindingStatus $status,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ?float $measured,
        ?float $allowed,
        string $unit,
        string $message,
        array $evidence = [],
        array $details = [],
    ): Finding {
        return new Finding(
            rule: $rule,
            status: $status,
            certainty: $this->certainty($status, $evidence),
            severity: Severity::forStatus($status),
            driverId: $context->timeline->driverId,
            periodStart: $start,
            periodEnd: $end,
            measuredValue: $measured,
            allowedValue: $allowed,
            unit: $unit,
            message: $message,
            relatedActivityIds: self::ids($evidence),
            details: $details,
        );
    }

    /** POTENTIAL when the outcome rests on CAN / card-out / otherwise uncertain records. */
    protected function certainty(FindingStatus $status, array $evidence): Certainty
    {
        if ($status === FindingStatus::INCOMPLETE_DATA) {
            return Certainty::POTENTIAL;
        }

        foreach ($evidence as $activity) {
            if ($activity->uncertain && $activity->type !== ActivityType::UNKNOWN) {
                return Certainty::POTENTIAL;
            }
        }

        return Certainty::CONFIRMED;
    }

    /** @param list<Activity> $activities */
    protected static function ids(array $activities): array
    {
        return array_values(array_unique(array_merge([], ...array_map(fn (Activity $a) => $a->sourceEventIds, $activities))));
    }

    protected static function seconds(array $activities): int
    {
        return array_sum(array_map(fn (Activity $a) => $a->durationSeconds(), $activities));
    }

    protected static function hours(int $seconds): float
    {
        return round($seconds / 3600, 2);
    }

    protected static function minutes(int $seconds): float
    {
        return round($seconds / 60, 1);
    }

    protected static function hm(int $seconds): string
    {
        return sprintf('%dh%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
