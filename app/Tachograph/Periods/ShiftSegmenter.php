<?php

namespace App\Tachograph\Periods;

use App\Tachograph\Data\Timeline;

/**
 * Splits a classified timeline into shifts at every DAILY_REST / WEEKLY_REST.
 * UNKNOWN never ends a shift: missing data between two shifts merges them, and rules
 * treat the result as incomplete rather than compliant.
 */
final class ShiftSegmenter
{
    /** @return list<Shift> */
    public function segment(Timeline $timeline): array
    {
        $shifts = [];
        $current = [];
        $previousRest = null;

        foreach ($timeline->activities() as $activity) {
            if ($activity->type->endsShift()) {
                if ($current !== []) {
                    $shifts[] = new Shift($current, $previousRest, $activity);
                    $current = [];
                }
                $previousRest = $activity;

                continue;
            }

            $current[] = $activity;
        }

        if ($current !== []) {
            $shifts[] = new Shift($current, $previousRest, null);
        }

        return $shifts;
    }
}
