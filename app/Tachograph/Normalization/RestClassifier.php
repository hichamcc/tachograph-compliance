<?php

namespace App\Tachograph\Normalization;

use App\Tachograph\Config\TachoConfig;
use App\Tachograph\Data\Activity;
use App\Tachograph\Data\ActivityType;

/**
 * Mapon does not distinguish breaks from rests; classify each continuous rest by duration:
 *   >= reduced weekly rest minimum (24h) → WEEKLY_REST (candidate; validated by WeeklyRestRule)
 *   >= reduced daily rest minimum (9h)   → DAILY_REST (also the 9h part of a 3h + 9h split)
 *   otherwise                           → BREAK (incl. the 3h first part of a split daily rest)
 * UNKNOWN is never classified as rest.
 */
final class RestClassifier
{
    public function __construct(private readonly TachoConfig $config) {}

    /**
     * @param  list<Activity>  $activities  contiguous, merged activities
     * @return list<Activity>
     */
    public function classify(array $activities): array
    {
        $weekly = $this->config->hours('reduced_weekly_rest_minimum_hours');
        $daily = min(
            $this->config->hours('reduced_daily_rest_minimum_hours'),
            $this->config->hours('split_daily_rest_second_hours'),
        );

        return array_map(function (Activity $activity) use ($weekly, $daily) {
            if (! $activity->type->isRestLike()) {
                return $activity;
            }

            $duration = $activity->durationSeconds();

            return $activity->withType(match (true) {
                $duration >= $weekly => ActivityType::WEEKLY_REST,
                $duration >= $daily => ActivityType::DAILY_REST,
                default => ActivityType::BREAK,
            });
        }, $activities);
    }
}
