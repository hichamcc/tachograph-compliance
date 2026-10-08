<?php

namespace App\Tachograph\Normalization;

use App\Tachograph\Config\TachoConfig;
use App\Tachograph\Data\Activity;
use App\Tachograph\Data\ActivitySource;

/**
 * Timeline-level checks on one driver's normalized activities (overlaps, gaps,
 * vehicle conflicts). Record-level checks happen in the normalizers.
 */
final class DataValidator
{
    public function __construct(private readonly TachoConfig $config) {}

    /**
     * @param  list<Activity>  $activities  one driver, sorted by start
     * @return list<DataIssue>
     */
    public function validate(array $activities): array
    {
        $issues = [];
        $latest = null; // activity reaching furthest so far

        foreach ($activities as $activity) {
            if ($latest !== null) {
                // A Mapon gap filler overlapped by real data is superseded, not a conflict.
                $filler = $activity->source === ActivitySource::UNKNOWN || $latest->source === ActivitySource::UNKNOWN;

                if ($activity->start < $latest->end && $filler) {
                    // no issue
                } elseif ($activity->start < $latest->end) {
                    $overlapEnd = min($activity->end, $latest->end);
                    $context = [
                        'types' => [$latest->type->value, $activity->type->value],
                        'sources' => [$latest->source->value, $activity->source->value],
                    ];

                    $issues[] = DataIssue::make(IssueType::OVERLAPPING_ACTIVITY, 'Two activity records overlap.', $activity->driverId, $activity->start, $overlapEnd, $context);

                    if ($latest->vehicleId !== null && $activity->vehicleId !== null && $latest->vehicleId !== $activity->vehicleId) {
                        $issues[] = DataIssue::make(
                            IssueType::INCONSISTENT_ASSOCIATION,
                            'Driver recorded on two vehicles at the same time.',
                            $activity->driverId,
                            $activity->start,
                            $overlapEnd,
                            ['vehicles' => [$latest->vehicleId, $activity->vehicleId]],
                        );
                    }
                } else {
                    $gap = $activity->start->getTimestamp() - $latest->end->getTimestamp();

                    if ($gap > $this->config->gapToleranceSeconds) {
                        $issues[] = DataIssue::make(
                            IssueType::TIMELINE_GAP,
                            'No activity data for '.self::humanDuration($gap).'.',
                            $activity->driverId,
                            $latest->end,
                            $activity->start,
                            ['seconds' => $gap],
                        );
                    }
                }
            }

            if ($latest === null || $activity->end > $latest->end) {
                $latest = $activity;
            }
        }

        return $issues;
    }

    private static function humanDuration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return $h > 0 ? sprintf('%dh %02dm', $h, $m) : sprintf('%dm %02ds', $m, $seconds % 60);
    }
}
