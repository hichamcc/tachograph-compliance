<?php

namespace Tests\Unit\Tachograph\Rules;

use App\Tachograph\Compliance\FindingStatus as S;
use App\Tachograph\Data\Period;
use App\Tachograph\Rules\Rule;
use App\Tachograph\Rules\TwoWeekDrivingRule;
use Tests\Support\TimelineFactory;

class TwoWeekDrivingRuleTest extends RuleTestCase
{
    protected function rule(): Rule
    {
        return new TwoWeekDrivingRule;
    }

    /** Two weeks from Monday 2026-09-21; one driving block per day. */
    private function twoWeeks(array $drivingPerDay): array
    {
        $f = TimelineFactory::driver()->startAt('2026-09-21 00:00');

        foreach ($drivingPerDay as $driving) {
            $f->rest('6h')->drive($driving)->rest((18 * 3600 - TimelineFactory::seconds($driving)).'s');
        }

        return $this->evaluate($f->build(), Period::fromStrings('2026-09-28 00:00', '2026-10-05 00:00'));
    }

    public function test_exactly_90h_is_compliant(): void
    {
        // 46h + 44h
        $findings = $this->twoWeeks([...array_fill(0, 6, '6h30m'), '7h', ...array_fill(0, 6, '6h'), '8h']);

        $this->assertSame(90.0, $findings[0]->measuredValue);
        $this->assertStatuses([S::COMPLIANT], $findings);
    }

    public function test_90h_plus_one_minute_is_a_violation(): void
    {
        $findings = $this->twoWeeks([...array_fill(0, 6, '6h30m'), '7h', ...array_fill(0, 6, '6h'), '8h1m']);

        $this->assertStatuses([S::VIOLATION], $findings);
        $this->assertSame(90.02, $findings[0]->measuredValue);
    }

    public function test_previous_week_missing_is_incomplete(): void
    {
        $f = TimelineFactory::driver()->startAt('2026-09-28 00:00');
        for ($i = 0; $i < 7; $i++) {
            $f->rest('6h')->drive('7h')->rest('11h');
        }

        $findings = $this->evaluate($f->build(), Period::fromStrings('2026-09-28 00:00', '2026-10-05 00:00'));

        $this->assertStatuses([S::INCOMPLETE_DATA], $findings);
        $this->assertFalse($findings[0]->details['previous_week_covered']);
    }

    public function test_previous_week_missing_but_already_over_limit_is_a_violation(): void
    {
        $f = TimelineFactory::driver()->startAt('2026-09-28 00:00');
        for ($i = 0; $i < 7; $i++) {
            $f->rest('3h')->drive('14h')->rest('7h'); // unrealistic, 98h in one week
        }

        $findings = $this->evaluate($f->build(), Period::fromStrings('2026-09-28 00:00', '2026-10-05 00:00'));

        $this->assertStatuses([S::VIOLATION], $findings);
    }
}
