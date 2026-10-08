<?php

namespace Tests\Unit\Tachograph\Rules;

use App\Tachograph\Compliance\Certainty;
use App\Tachograph\Compliance\FindingStatus as S;
use App\Tachograph\Data\ActivitySource;
use App\Tachograph\Data\Period;
use App\Tachograph\Rules\BreakRule;
use App\Tachograph\Rules\Rule;
use Tests\Support\TimelineFactory;

class BreakRuleTest extends RuleTestCase
{
    protected function rule(): Rule
    {
        return new BreakRule;
    }

    private function breaks(TimelineFactory $f): array
    {
        return $this->evaluate($f->build());
    }

    public function test_4h_drive_and_45m_break_is_compliant(): void
    {
        $this->assertStatuses([S::COMPLIANT], $this->breaks(TimelineFactory::driver()->drive('4h')->rest('45m')));
    }

    public function test_exactly_4h30_and_exactly_45m_is_compliant(): void
    {
        $findings = $this->breaks(TimelineFactory::driver()->drive('4h30m')->rest('45m'));

        $this->assertStatuses([S::COMPLIANT], $findings);
        $this->assertSame(4.5, $findings[0]->measuredValue);
    }

    public function test_one_minute_over_is_a_violation(): void
    {
        $findings = $this->breaks(TimelineFactory::driver()->drive('4h31m')->rest('45m'));

        $this->assertStatuses([S::VIOLATION], $findings);
        $this->assertSame(Certainty::CONFIRMED, $findings[0]->certainty);
        $this->assertSame(4.52, $findings[0]->measuredValue);
        $this->assertSame(4.5, $findings[0]->allowedValue);
        $this->assertNotEmpty($findings[0]->relatedActivityIds);
    }

    public function test_44m_break_does_not_reset(): void
    {
        $this->assertStatuses([S::VIOLATION], $this->breaks(TimelineFactory::driver()->drive('4h')->rest('44m')->drive('1h')->rest('45m')));
    }

    public function test_split_15_then_30_is_compliant(): void
    {
        $findings = $this->breaks(TimelineFactory::driver()->drive('2h')->rest('15m')->drive('2h30m')->rest('30m'));

        $this->assertStatuses([S::COMPLIANT], $findings);
        $this->assertTrue($findings[0]->details['split_break']);
    }

    public function test_split_in_wrong_order_30_then_15_is_a_violation(): void
    {
        $this->assertStatuses([S::VIOLATION], $this->breaks(TimelineFactory::driver()->drive('2h')->rest('30m')->drive('2h')->rest('15m')->drive('1h')->rest('45m')));
    }

    public function test_driving_between_split_parts_counts(): void
    {
        $findings = $this->breaks(TimelineFactory::driver()->drive('2h')->rest('15m')->drive('2h36m')->rest('30m'));

        $this->assertStatuses([S::VIOLATION], $findings);
        $this->assertSame(4.6, $findings[0]->measuredValue);
    }

    public function test_work_and_availability_are_not_breaks(): void
    {
        $this->assertStatuses([S::VIOLATION], $this->breaks(TimelineFactory::driver()->drive('3h')->work('1h')->drive('2h')->rest('45m')));
        $this->assertStatuses([S::VIOLATION], $this->breaks(TimelineFactory::driver()->drive('3h')->available('1h')->drive('2h')->rest('45m')));
    }

    public function test_availability_can_be_configured_as_break(): void
    {
        $timeline = TimelineFactory::driver()->drive('3h')->available('1h')->drive('2h')->rest('45m')->build();

        $this->assertStatuses([S::COMPLIANT, S::COMPLIANT], $this->evaluate($timeline, config: ['data' => ['availability_counts_as_break' => true]]));
    }

    public function test_daily_rest_is_a_qualifying_break(): void
    {
        $this->assertStatuses([S::COMPLIANT, S::COMPLIANT], $this->breaks(TimelineFactory::driver()->drive('4h')->rest('11h')->drive('4h')->rest('45m')));
    }

    public function test_unknown_gap_that_may_have_been_a_break_gives_incomplete_data(): void
    {
        $findings = $this->breaks(TimelineFactory::driver()->drive('2h30m')->unknown('50m')->drive('2h30m')->rest('45m'));

        $this->assertStatuses([S::INCOMPLETE_DATA], $findings);
        $this->assertSame(Certainty::POTENTIAL, $findings[0]->certainty);
    }

    public function test_violation_before_the_unknown_gap_is_still_confirmed(): void
    {
        $this->assertStatuses([S::VIOLATION], $this->breaks(TimelineFactory::driver()->drive('5h')->unknown('50m')->drive('1h')->rest('45m')));
    }

    public function test_short_unknown_gap_cannot_be_a_break(): void
    {
        $this->assertStatuses([S::VIOLATION], $this->breaks(TimelineFactory::driver()->drive('2h30m')->unknown('10m')->drive('2h30m')->rest('45m')));
    }

    public function test_can_source_driving_makes_violation_potential(): void
    {
        $findings = $this->breaks(TimelineFactory::driver()->drive('3h')->drive('2h', ActivitySource::CAN, uncertain: true)->rest('45m'));

        $this->assertStatuses([S::VIOLATION], $findings);
        $this->assertSame(Certainty::POTENTIAL, $findings[0]->certainty);
    }

    public function test_open_period_at_end_of_data(): void
    {
        $findings = $this->breaks(TimelineFactory::driver()->rest('45m')->drive('5h'));

        $this->assertStatuses([S::VIOLATION], $findings);
        $this->assertTrue($findings[0]->details['open']);
    }

    public function test_only_periods_overlapping_the_report_are_returned(): void
    {
        $timeline = TimelineFactory::driver()->startAt('2026-09-27 08:00')->drive('5h')->rest('11h')->drive('4h')->rest('45m')->build();
        $findings = $this->evaluate($timeline, Period::fromStrings('2026-09-28 00:00', '2026-09-29 00:00'));

        $this->assertStatuses([S::COMPLIANT], $findings);
    }
}
