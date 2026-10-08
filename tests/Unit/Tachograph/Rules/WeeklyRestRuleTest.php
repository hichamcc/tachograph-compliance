<?php

namespace Tests\Unit\Tachograph\Rules;

use App\Tachograph\Compliance\FindingStatus as S;
use App\Tachograph\Data\Period;
use App\Tachograph\Rules\Rule;
use App\Tachograph\Rules\WeeklyRestRule;
use Tests\Support\TimelineFactory;

class WeeklyRestRuleTest extends RuleTestCase
{
    protected function rule(): Rule
    {
        return new WeeklyRestRule;
    }

    public function test_regular_weekly_rest_within_six_24h_periods(): void
    {
        $f = TimelineFactory::driver()->startAt('2026-09-12 00:00')->rest('45h');
        self::workDays($f, 6)->work('13h')->rest('45h'); // 131h + 13h = exactly 144h

        $findings = $this->findings($f->build(), WeeklyRestRule::CODE);

        $this->assertStatuses([S::COMPLIANT, S::COMPLIANT], $findings);
        $this->assertSame(144.0, $findings[1]->measuredValue); // exactly 6 x 24h: boundary is compliant
        $this->assertSame('regular', $findings[1]->details['rest_type']);
    }

    public function test_weekly_rest_starting_one_minute_too_late(): void
    {
        $f = TimelineFactory::driver()->startAt('2026-09-12 00:00')->rest('45h');
        self::workDays($f, 6)->work('13h1m')->rest('45h');

        $this->assertStatuses([S::COMPLIANT, S::VIOLATION], $this->findings($f->build(), WeeklyRestRule::CODE));
    }

    public function test_exactly_24h_is_a_reduced_weekly_rest(): void
    {
        $f = TimelineFactory::driver()->startAt('2026-09-12 00:00')->rest('45h');
        self::workDays($f, 5)->rest('24h');

        $findings = $this->findings($f->build(), WeeklyRestRule::CODE);

        $this->assertStatuses([S::COMPLIANT, S::COMPLIANT], $findings);
        $this->assertSame('reduced', $findings[1]->details['rest_type']);
    }

    public function test_23h59m_is_not_a_weekly_rest(): void
    {
        $f = TimelineFactory::driver()->startAt('2026-09-12 00:00')->rest('45h');
        self::workDays($f, 5)->rest('23h59m');
        self::workDays($f, 2);

        $findings = $this->findings($f->build(), WeeklyRestRule::CODE);

        $this->assertStatuses([S::COMPLIANT, S::VIOLATION], $findings);
        $this->assertStringContainsString('No weekly rest', $findings[1]->message);
    }

    public function test_unknown_data_that_may_hide_a_weekly_rest_is_incomplete(): void
    {
        $f = TimelineFactory::driver()->startAt('2026-09-12 00:00')->rest('45h');
        self::workDays($f, 3)->unknown('30h');
        self::workDays($f, 3)->rest('45h');

        $this->assertStatuses([S::COMPLIANT, S::INCOMPLETE_DATA], $this->findings($f->build(), WeeklyRestRule::CODE));
    }

    /** Weekly rests inside each week: Mon 2026-09-14 start, 5 work days, rest, repeat. */
    private function weeks(array $restDurations): TimelineFactory
    {
        $f = TimelineFactory::driver()->startAt('2026-09-14 00:00');
        foreach ($restDurations as $rest) {
            self::workDays($f, 5)->rest($rest);
        }

        return self::workDays($f, 4);
    }

    public function test_two_weeks_with_regular_and_reduced_is_compliant(): void
    {
        $timeline = $this->weeks(['45h', '24h'])->build();

        $this->assertStatuses([S::COMPLIANT], $this->findings($timeline, WeeklyRestRule::PATTERN_CODE, Period::fromStrings('2026-09-21 00:00', '2026-09-28 00:00')));
    }

    public function test_two_reduced_weekly_rests_in_consecutive_weeks_is_a_violation(): void
    {
        $timeline = $this->weeks(['24h', '24h'])->build();
        $findings = $this->findings($timeline, WeeklyRestRule::PATTERN_CODE, Period::fromStrings('2026-09-21 00:00', '2026-09-28 00:00'));

        $this->assertStatuses([S::VIOLATION], $findings);
        $this->assertSame(0, $findings[0]->details['regular']);
        $this->assertSame(2, $findings[0]->details['reduced']);
    }

    public function test_long_rest_spanning_several_weeks_counts_in_each_week(): void
    {
        // Three weeks off (real-data pattern), then back to work.
        $f = TimelineFactory::driver()->startAt('2026-09-11 22:00')->rest('541h45m');
        self::workDays($f, 5)->rest('58h');
        self::workDays($f, 2);

        $findings = $this->findings($f->build(), WeeklyRestRule::PATTERN_CODE, Period::fromStrings('2026-09-21 00:00', '2026-10-12 00:00'));

        $this->assertStatuses([S::COMPLIANT, S::COMPLIANT, S::COMPLIANT], $findings);
    }

    public function test_pattern_with_previous_week_not_covered_is_incomplete(): void
    {
        $timeline = $this->weeks(['24h', '24h'])->build();
        $findings = $this->findings($timeline, WeeklyRestRule::PATTERN_CODE, Period::fromStrings('2026-09-14 00:00', '2026-09-21 00:00'));

        $this->assertStatuses([S::INCOMPLETE_DATA], $findings);
    }

    public function test_reduced_rest_compensated_by_extended_weekly_rest(): void
    {
        // 24h reduced (owes 21h), compensated with a 66h weekly rest.
        $timeline = $this->weeks(['24h', '66h'])->build();
        $findings = $this->findings($timeline, WeeklyRestRule::COMPENSATION_CODE);

        $this->assertStatuses([S::COMPLIANT], $findings);
        $this->assertSame('COMPLETED', $findings[0]->details['compensation_status']);
        $this->assertSame(21.0, $findings[0]->measuredValue);
    }

    public function test_compensation_attached_to_a_daily_rest(): void
    {
        // 40h reduced owes 5h → a 14h daily rest (9h + 5h) compensates it.
        $f = TimelineFactory::driver()->startAt('2026-09-14 00:00');
        self::workDays($f, 5)->rest('40h')->drive('4h')->rest('45m')->drive('4h')->rest('14h');
        self::workDays($f, 2);

        $findings = $this->findings($f->build(), WeeklyRestRule::COMPENSATION_CODE);
        $this->assertSame('COMPLETED', $findings[0]->details['compensation_status']);
    }

    public function test_compensation_pending_before_deadline(): void
    {
        $timeline = $this->weeks(['24h', '45h'])->build();
        $findings = $this->findings($timeline, WeeklyRestRule::COMPENSATION_CODE);

        $this->assertStatuses([S::WARNING], $findings);
        $this->assertSame('PENDING', $findings[0]->details['compensation_status']);
        $this->assertSame('2026-10-12T00:00:00Z', $findings[0]->details['due_by']);
    }

    public function test_compensation_not_taken_by_deadline_is_a_violation(): void
    {
        $timeline = $this->weeks(['24h', '45h', '45h', '45h', '45h'])->build();
        $findings = $this->findings($timeline, WeeklyRestRule::COMPENSATION_CODE);

        $this->assertStatuses([S::VIOLATION], $findings);
        $this->assertSame('OVERDUE', $findings[0]->details['compensation_status']);
    }
}
