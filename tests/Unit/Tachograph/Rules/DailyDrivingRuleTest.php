<?php

namespace Tests\Unit\Tachograph\Rules;

use App\Tachograph\Compliance\FindingStatus as S;
use App\Tachograph\Rules\DailyDrivingRule;
use App\Tachograph\Rules\Rule;
use Tests\Support\TimelineFactory;

class DailyDrivingRuleTest extends RuleTestCase
{
    protected function rule(): Rule
    {
        return new DailyDrivingRule;
    }

    /** Shift preceded by a daily rest so its start is known. */
    private function shift(string $first, string $second): TimelineFactory
    {
        return TimelineFactory::driver()->startAt('2026-09-28 00:00')->rest('11h')->drive($first)->rest('45m')->drive($second)->rest('11h');
    }

    private function daily(TimelineFactory $f): array
    {
        return $this->findings($f->build(), DailyDrivingRule::CODE);
    }

    public function test_8h_is_compliant(): void
    {
        $this->assertStatuses([S::COMPLIANT], $this->daily($this->shift('4h', '4h')));
    }

    public function test_exactly_9h_is_compliant(): void
    {
        $findings = $this->daily($this->shift('4h30m', '4h30m'));

        $this->assertStatuses([S::COMPLIANT], $findings);
        $this->assertFalse($findings[0]->details['extended']);
    }

    public function test_over_9h_is_an_extended_day(): void
    {
        $findings = $this->daily($this->shift('4h30m', '4h31m'));

        $this->assertStatuses([S::WARNING], $findings);
        $this->assertTrue($findings[0]->details['extended']);
        $this->assertSame(10.0, $findings[0]->allowedValue);
    }

    public function test_exactly_10h_on_extended_day_is_not_a_violation(): void
    {
        $this->assertStatuses([S::WARNING], $this->daily(TimelineFactory::driver()->rest('11h')->drive('4h30m')->rest('45m')->drive('4h30m')->rest('45m')->drive('1h')->rest('11h')));
    }

    public function test_over_10h_is_a_violation(): void
    {
        $findings = $this->daily(TimelineFactory::driver()->rest('11h')->drive('4h30m')->rest('45m')->drive('4h30m')->rest('45m')->drive('1h1m')->rest('11h'));

        $this->assertStatuses([S::VIOLATION], $findings);
        $this->assertSame(10.02, $findings[0]->measuredValue);
    }

    public function test_daily_driving_is_per_shift_not_calendar_day(): void
    {
        // 6h before and 6h after midnight in one shift = 12h.
        $findings = $this->daily(TimelineFactory::driver()->startAt('2026-09-28 07:00')->rest('11h')->drive('4h')->rest('45m')->drive('2h')->rest('45m')->drive('4h')->rest('45m')->drive('2h')->rest('11h'));

        $this->assertStatuses([S::VIOLATION], $findings);
    }

    public function test_shift_starting_before_data_is_incomplete(): void
    {
        $this->assertStatuses([S::INCOMPLETE_DATA], $this->daily(TimelineFactory::driver()->drive('4h')->rest('11h')));
    }

    public function test_long_unknown_between_shifts_is_incomplete_not_violation(): void
    {
        $f = TimelineFactory::driver()->rest('11h')->drive('4h')->rest('45m')->drive('4h')->unknown('11h')->drive('4h')->rest('45m')->drive('4h')->rest('11h');

        $this->assertStatuses([S::INCOMPLETE_DATA], $this->daily($f));
    }

    public function test_unknown_that_could_push_over_the_limit_is_incomplete(): void
    {
        $f = TimelineFactory::driver()->rest('11h')->drive('4h')->rest('45m')->drive('4h')->unknown('3h')->rest('11h');

        $this->assertStatuses([S::INCOMPLETE_DATA], $this->daily($f));
    }

    public function test_two_extended_days_per_week_allowed_third_is_violation(): void
    {
        $two = TimelineFactory::driver()->startAt('2026-09-28 00:00')->rest('11h');
        $three = TimelineFactory::driver()->startAt('2026-09-28 00:00')->rest('11h');

        foreach ([[$two, 2], [$three, 3]] as [$f, $n]) {
            for ($i = 0; $i < 4; $i++) {
                $f->drive('4h30m')->rest('45m')->drive($i < $n ? '5h' : '4h')->rest('13h');
            }
        }

        $this->assertStatuses([S::COMPLIANT], $this->findings($two->build(), DailyDrivingRule::EXTENDED_CODE));

        $week = $this->findings($three->build(), DailyDrivingRule::EXTENDED_CODE);
        $this->assertStatuses([S::VIOLATION], $week);
        $this->assertSame(3.0, $week[0]->measuredValue);
        $this->assertSame(2.0, $week[0]->allowedValue);
    }
}
