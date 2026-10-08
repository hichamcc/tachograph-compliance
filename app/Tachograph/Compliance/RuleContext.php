<?php

namespace App\Tachograph\Compliance;

use App\Tachograph\Config\TachoConfig;
use App\Tachograph\Data\Activity;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Data\Period;
use App\Tachograph\Data\Timeline;
use App\Tachograph\Periods\Shift;
use App\Tachograph\Periods\ShiftSegmenter;
use App\Tachograph\Periods\WeekCalendar;
use DateTimeImmutable;

/**
 * Everything a rule needs for one evaluation. Rules look at the whole timeline (incl. history
 * fetched before the report start) but only report units that fall inside the report period.
 */
final class RuleContext
{
    private ?array $shifts = null;

    public readonly WeekCalendar $calendar;

    public function __construct(
        public readonly Timeline $timeline,
        public readonly Period $report,
        public readonly TachoConfig $config,
    ) {
        $this->calendar = new WeekCalendar($config->weekTimezone);
    }

    /** @return list<Shift> */
    public function shifts(): array
    {
        return $this->shifts ??= (new ShiftSegmenter)->segment($this->timeline);
    }

    public function coverage(): Period
    {
        return $this->timeline->coverage() ?? new Period($this->report->start, $this->report->start);
    }

    /** End of available data. Time after it has not happened yet (or is not fetched) and is not "unknown". */
    public function horizon(): DateTimeImmutable
    {
        return $this->coverage()->end;
    }

    /** @return list<Period> fixed weeks overlapping the report period */
    public function reportWeeks(): array
    {
        return $this->calendar->weeksOverlapping($this->report);
    }

    public function inReport(DateTimeImmutable $time): bool
    {
        return $this->report->contains($time);
    }

    public function overlapsReport(Period $period): bool
    {
        return $period->durationSeconds() === 0
            ? $this->inReport($period->start)
            : $this->report->overlaps($period);
    }

    /** The part of the period before the horizon, or null if it lies entirely after it. */
    public function untilHorizon(Period $period): ?Period
    {
        $end = min($period->end, $this->horizon());

        return $end > $period->start ? new Period($period->start, $end) : null;
    }

    /** @return list<Activity> */
    public function activitiesOf(ActivityType $type, Period $period): array
    {
        return array_values(array_filter($this->timeline->between($period), fn (Activity $a) => $a->type === $type));
    }

    public function longestUnknownSeconds(Period $period): int
    {
        $longest = max([0, ...array_map(fn (Activity $a) => $a->durationSeconds(), $this->activitiesOf(ActivityType::UNKNOWN, $period))]);

        // Part of the period not covered by the timeline at all.
        $coverage = $this->coverage();
        $before = $coverage->start > $period->start ? min($coverage->start, $period->end)->getTimestamp() - $period->start->getTimestamp() : 0;
        $after = $coverage->end < $period->end ? $period->end->getTimestamp() - max($coverage->end, $period->start)->getTimestamp() : 0;

        return max($longest, $before, $after);
    }
}
