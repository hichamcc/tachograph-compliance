<?php

namespace Tests\Unit\Tachograph\Rules;

use App\Tachograph\Compliance\Certainty;
use App\Tachograph\Compliance\FindingStatus as S;
use App\Tachograph\Data\ActivitySource;
use App\Tachograph\Rules\DailyRestRule;
use App\Tachograph\Rules\Rule;
use Tests\Support\TimelineFactory;

class DailyRestRuleTest extends RuleTestCase
{
    protected function rule(): Rule
    {
        return new DailyRestRule;
    }

    /** A known shift start (after an 11h rest) with 8h45m of duty. */
    private function afterRest(): TimelineFactory
    {
        return TimelineFactory::driver()->startAt('2026-09-28 00:00')->rest('11h')->drive('4h')->rest('45m')->drive('4h');
    }

    private function daily(TimelineFactory $f): array
    {
        return $this->findings($f->build(), DailyRestRule::CODE);
    }

    public function test_11h_regular_daily_rest(): void
    {
        $findings = $this->daily($this->afterRest()->rest('11h'));

        $this->assertStatuses([S::COMPLIANT], $findings);
        $this->assertSame('regular', $findings[0]->details['rest_type']);
    }

    public function test_exactly_9h_is_a_reduced_daily_rest(): void
    {
        $findings = $this->daily($this->afterRest()->rest('9h'));

        $this->assertStatuses([S::COMPLIANT], $findings);
        $this->assertSame('reduced', $findings[0]->details['rest_type']);
        $this->assertSame(1, $findings[0]->details['reductions_used']);
        $this->assertSame(2, $findings[0]->details['reductions_remaining']);
    }

    public function test_8h59m_is_a_violation(): void
    {
        $findings = $this->daily($this->afterRest()->rest('8h59m')->drive('4h')->rest('11h'));

        $this->assertStatuses([S::VIOLATION], $findings);
        $this->assertSame(Certainty::CONFIRMED, $findings[0]->certainty);
        $this->assertSame(8.98, $findings[0]->measuredValue); // longest rest taken in the window
    }

    public function test_two_long_breaks_split_by_a_short_drive_are_not_a_daily_rest(): void
    {
        // Real-data pattern: 4h45 + 6h37 overnight, separated by a 2-minute drive.
        $findings = $this->daily($this->afterRest()->rest('4h45m')->drive('2m')->rest('6h37m')->drive('4h')->rest('45m')->drive('4h')->rest('11h'));

        $this->assertStatuses([S::VIOLATION], $findings);
        $this->assertSame(6.62, $findings[0]->measuredValue);
        $this->assertStringContainsString('longest rest was 6h37', $findings[0]->message);
        $this->assertCount(3, $findings[0]->relatedActivityIds); // 4h45 (possible split part), the 6h37 it refers to, the following rest
    }

    public function test_split_3h_then_9h_is_regular(): void
    {
        $findings = $this->daily(TimelineFactory::driver()->rest('11h')->drive('4h')->rest('3h')->drive('4h')->rest('9h'));

        $this->assertStatuses([S::COMPLIANT], $findings);
        $this->assertSame('split', $findings[0]->details['rest_type']);
        $this->assertSame(0, $findings[0]->details['reductions_used']);
    }

    public function test_split_in_wrong_order_is_a_reduced_rest_not_split(): void
    {
        // 9h first then 3h: the 9h already ends the shift as a reduced rest.
        $findings = $this->daily(TimelineFactory::driver()->rest('11h')->drive('4h')->rest('9h')->drive('4h')->rest('3h')->drive('1h')->rest('11h'));

        $this->assertSame('reduced', $findings[0]->details['rest_type']);
    }

    public function test_rest_must_fall_within_24h_of_previous_rest(): void
    {
        // 16h of duty then an 11h rest: only 8h fall inside the 24h window.
        $findings = $this->daily($this->afterRest()->work('7h15m')->rest('11h'));

        $this->assertStatuses([S::VIOLATION], $findings);
        $this->assertSame(8.0, $findings[0]->measuredValue);
    }

    public function test_fourth_reduced_rest_between_weekly_rests_is_a_violation(): void
    {
        $f = TimelineFactory::driver()->rest('45h');
        for ($i = 0; $i < 4; $i++) {
            $f->drive('4h')->rest('45m')->drive('4h')->rest('9h');
        }

        $all = $this->evaluate($f->build());
        $reductions = array_values(array_filter($all, fn ($x) => $x->rule === DailyRestRule::REDUCTIONS_CODE));

        $this->assertStatuses([S::VIOLATION], $reductions);
        $this->assertSame(4.0, $reductions[0]->measuredValue);
    }

    public function test_weekly_rest_resets_the_reduction_count(): void
    {
        $f = TimelineFactory::driver()->rest('45h');
        for ($i = 0; $i < 3; $i++) {
            $f->drive('4h')->rest('45m')->drive('4h')->rest('9h');
        }
        $f->drive('4h')->rest('45h')->drive('4h')->rest('45m')->drive('4h')->rest('9h');

        $findings = $this->evaluate($f->build());

        $this->assertSame([], array_filter($findings, fn ($x) => $x->rule === DailyRestRule::REDUCTIONS_CODE));
        $this->assertSame(1, end($findings)->details['reductions_used']);
    }

    public function test_unknown_between_shifts_is_incomplete_not_rest(): void
    {
        $findings = $this->daily($this->afterRest()->unknown('11h')->drive('4h')->rest('11h'));

        $this->assertStatuses([S::INCOMPLETE_DATA], $findings);
    }

    public function test_card_out_rest_makes_finding_potential(): void
    {
        $findings = $this->daily($this->afterRest()->rest('11h', ActivitySource::DDD, uncertain: true));

        $this->assertStatuses([S::COMPLIANT], $findings);
        $this->assertSame(Certainty::POTENTIAL, $findings[0]->certainty);
    }

    public function test_open_shift_within_24h_is_not_evaluated_yet(): void
    {
        $this->assertSame([], $this->daily($this->afterRest()));
    }

    public function test_shift_without_known_previous_rest_is_incomplete(): void
    {
        $this->assertStatuses([S::INCOMPLETE_DATA], $this->daily(TimelineFactory::driver()->drive('4h')->rest('11h')));
    }
}
