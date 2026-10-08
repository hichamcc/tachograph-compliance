<?php

namespace App\Tachograph\Periods;

use App\Tachograph\Data\Activity;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Data\Period;
use DateTimeImmutable;

/**
 * Duty period between two daily/weekly rests.
 */
final readonly class Shift
{
    public DateTimeImmutable $start;

    public DateTimeImmutable $end;

    /**
     * @param  non-empty-list<Activity>  $activities
     * @param  Activity|null  $previousRest  rest that ended right before this shift (null: shift began before available data)
     * @param  Activity|null  $nextRest  rest that follows this shift (null: shift still open at the end of the data)
     */
    public function __construct(
        public array $activities,
        public ?Activity $previousRest,
        public ?Activity $nextRest,
    ) {
        $this->start = $activities[0]->start;
        $this->end = $activities[array_key_last($activities)]->end;
    }

    public function period(): Period
    {
        return new Period($this->start, $this->end);
    }

    public function startKnown(): bool
    {
        return $this->previousRest !== null;
    }

    public function secondsOf(ActivityType $type): int
    {
        return array_sum(array_map(fn (Activity $a) => $a->durationSeconds(), $this->activitiesOf($type)));
    }

    public function drivingSeconds(): int
    {
        return $this->secondsOf(ActivityType::DRIVING);
    }

    public function longestUnknownSeconds(): int
    {
        return max([0, ...array_map(fn (Activity $a) => $a->durationSeconds(), $this->activitiesOf(ActivityType::UNKNOWN))]);
    }

    /** @return list<Activity> */
    public function activitiesOf(ActivityType $type): array
    {
        return array_values(array_filter($this->activities, fn (Activity $a) => $a->type === $type));
    }
}
