<?php

namespace Tests\Unit\Tachograph\Rules;

use App\Tachograph\Compliance\FindingStatus as S;
use App\Tachograph\Data\Period;
use App\Tachograph\Rules\Rule;
use App\Tachograph\Rules\WeeklyDrivingRule;
use Tests\Support\TimelineFactory;

class WeeklyDrivingRuleTest extends RuleTestCase
{
    protected function rule(): Rule
    {
        return new WeeklyDrivingRule;
    }

    /** Monday 2026-09-28 00:00 → Monday 2026-10-05 00:00, one block of driving per day. */
    private function week(array $drivingPerDay): TimelineFactory
    {
        $f = TimelineFactory::driver()->startAt('2026-09-28 00:00');

        foreach ($drivingPerDay as $driving) {
            $seconds = TimelineFactory::seconds($driving);
            $f->rest('6h')->drive($driving)->rest((18 * 3600 - $seconds).'s');
        }

        return $f;
    }

    private function weekly(TimelineFactory $f): array
    {
        return $this->evaluate($f->build(), Period::fromStrings('2026-09-28 00:00', '2026-10-05 00:00'));
    }

    public function test_50h_is_compliant(): void
    {
        $this->assertStatuses([S::COMPLIANT], $this->weekly($this->week(array_fill(0, 5, '10h'))));
    }

    public function test_exactly_56h_is_compliant(): void
    {
        $findings = $this->weekly($this->week(array_fill(0, 7, '8h')));

        $this->assertStatuses([S::COMPLIANT], $findings);
        $this->assertSame(56.0, $findings[0]->measuredValue);
        $this->assertTrue($findings[0]->details['week_complete']);
    }

    public function test_56h_plus_one_minute_is_a_violation(): void
    {
        $findings = $this->weekly($this->week([...array_fill(0, 6, '8h'), '8h1m']));

        $this->assertStatuses([S::VIOLATION], $findings);
        $this->assertSame('2026-W40', $findings[0]->details['week']);
    }

    public function test_activity_crossing_sunday_midnight_is_split(): void
    {
        $timeline = TimelineFactory::driver()->startAt('2026-10-04 12:00')->rest('10h')->drive('4h')->rest('10h')->build();
        $findings = $this->evaluate($timeline, Period::fromStrings('2026-10-04 00:00', '2026-10-06 00:00'));

        $this->assertSame([2.0, 2.0], array_map(fn ($f) => $f->measuredValue, $findings));
    }

    public function test_unknown_time_that_could_exceed_the_limit_is_incomplete(): void
    {
        $f = $this->week(array_fill(0, 5, '10h'))->unknown('2h');
        $timeline = $f->build(coverage: Period::fromStrings('2026-09-28 00:00', '2026-10-05 00:00'));

        $findings = $this->evaluate($timeline, Period::fromStrings('2026-09-28 00:00', '2026-10-05 00:00'));
        $this->assertStatuses([S::INCOMPLETE_DATA], $findings);
    }

    public function test_current_week_is_evaluated_up_to_the_end_of_data(): void
    {
        $findings = $this->weekly($this->week(['8h', '8h']));

        $this->assertStatuses([S::COMPLIANT], $findings);
        $this->assertFalse($findings[0]->details['week_complete']);
    }
}
