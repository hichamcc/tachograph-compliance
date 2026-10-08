<?php

namespace App\Tachograph\Periods;

use App\Tachograph\Data\Period;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Fixed weeks: Monday 00:00 – next Monday 00:00 in the configured week timezone.
 * Returned periods are in UTC.
 */
final class WeekCalendar
{
    private readonly DateTimeZone $tz;

    private readonly DateTimeZone $utc;

    public function __construct(string $timezone = 'UTC')
    {
        $this->tz = new DateTimeZone($timezone);
        $this->utc = new DateTimeZone('UTC');
    }

    public function weekOf(DateTimeImmutable $time): Period
    {
        $local = $time->setTimezone($this->tz);
        $start = $local->setTime(0, 0)->modify('-'.((int) $local->format('N') - 1).' days');

        return new Period($start->setTimezone($this->utc), $start->modify('+7 days')->setTimezone($this->utc));
    }

    public function previous(Period $week): Period
    {
        return $this->weekOf($week->start->modify('-1 day'));
    }

    public function next(Period $week): Period
    {
        return $this->weekOf($week->end);
    }

    /** @return list<Period> */
    public function weeksOverlapping(Period $period): array
    {
        $weeks = [];
        $week = $this->weekOf($period->start);

        do {
            $weeks[] = $week;
            $week = $this->next($week);
        } while ($week->start < $period->end);

        return $weeks;
    }

    /** ISO week label, e.g. "2026-W40". */
    public function label(Period $week): string
    {
        return $week->start->setTimezone($this->tz)->format('o-\WW');
    }
}
