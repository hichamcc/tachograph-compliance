<?php

namespace App\Tachograph\Data;

/**
 * Contiguous, sorted, classified activities of one driver (output of TimelineBuilder).
 */
final class Timeline
{
    /** @var list<Activity> */
    private array $activities;

    /** @param list<Activity> $activities */
    public function __construct(
        public readonly string $driverId,
        array $activities,
    ) {
        usort($activities, fn (Activity $a, Activity $b) => [$a->start, $a->end] <=> [$b->start, $b->end]);
        $this->activities = array_values($activities);
    }

    /** @return list<Activity> */
    public function activities(): array
    {
        return $this->activities;
    }

    /** @return list<Activity> */
    public function segments(): array
    {
        return $this->activities;
    }

    public function isEmpty(): bool
    {
        return $this->activities === [];
    }

    public function coverage(): ?Period
    {
        if ($this->isEmpty()) {
            return null;
        }

        return new Period($this->activities[0]->start, max(array_map(fn (Activity $a) => $a->end, $this->activities)));
    }

    /**
     * Activities overlapping the period, clipped to it.
     *
     * @return list<Activity>
     */
    public function between(Period $period): array
    {
        $result = [];

        foreach ($this->activities as $activity) {
            if ($activity->start >= $period->end) {
                break;
            }

            if ($clipped = $activity->clip($period)) {
                $result[] = $clipped;
            }
        }

        return $result;
    }

    /**
     * Seconds per activity type, optionally limited to a period. Time not covered by the
     * timeline inside the period is reported as UNKNOWN.
     *
     * @return array<string, int> keyed by ActivityType value
     */
    public function totals(?Period $period = null): array
    {
        $totals = array_fill_keys(array_map(fn (ActivityType $t) => $t->value, ActivityType::cases()), 0);
        $activities = $period ? $this->between($period) : $this->activities;

        foreach ($activities as $activity) {
            $totals[$activity->type->value] += $activity->durationSeconds();
        }

        if ($period) {
            $totals[ActivityType::UNKNOWN->value] += max(0, $period->durationSeconds() - array_sum($totals));
        }

        return $totals;
    }

    public function secondsOf(ActivityType $type, ?Period $period = null): int
    {
        return $this->totals($period)[$type->value];
    }
}
