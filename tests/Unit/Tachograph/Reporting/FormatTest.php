<?php

namespace Tests\Unit\Tachograph\Reporting;

use App\Tachograph\Reporting\Format;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    private function compensation(): array
    {
        return [
            'rule' => 'WEEKLY_REST_COMPENSATION', 'status' => 'WARNING', 'certainty' => 'CONFIRMED',
            'period_start' => '2026-09-17T08:00:00Z', 'period_end' => '2026-10-12T00:00:00Z',
            'measured_value' => 20.83, 'allowed_value' => null, 'unit' => 'hours', 'message' => '', 'related_activity_ids' => [],
            'details' => [
                'owed_hours' => 20.83, 'due_by' => '2026-10-12T00:00:00Z',
                'with_weekly_rest_hours' => 65.83, 'with_weekly_rest_start_by' => '2026-10-09T06:10:00Z',
                'with_daily_rest_hours' => 29.83, 'with_daily_rest_start_by' => '2026-10-10T18:10:00Z',
            ],
        ];
    }

    public function test_compensation_plan_in_local_time(): void
    {
        $text = Format::compensationPlan($this->compensation(), 'Europe/Copenhagen', new DateTimeImmutable('2026-10-01T00:00:00Z'));

        $this->assertSame(
            'Start break latest Fri 9 Oct 08:10 — 65h50 (45h weekly rest + 20h50 compensation), finished by Mon 12 Oct 02:00. Or: start latest Sat 10 Oct 20:10 with a daily rest of 29h50 (9h + 20h50).',
            $text,
        );
        $this->assertSame('Due Mon 12 Oct 02:00', Format::findingLabel($this->compensation(), 'Europe/Copenhagen'));
    }

    public function test_options_whose_start_has_passed_are_left_out(): void
    {
        $onlyDaily = Format::compensationPlan($this->compensation(), 'Europe/Copenhagen', new DateTimeImmutable('2026-10-09T12:00:00Z'));
        $this->assertSame('Start break latest Sat 10 Oct 20:10 — daily rest of 29h50 (9h + 20h50 compensation), finished by Mon 12 Oct 02:00.', $onlyDaily);

        $tooLate = Format::compensationPlan($this->compensation(), 'Europe/Copenhagen', new DateTimeImmutable('2026-10-11T00:00:00Z'));
        $this->assertStringStartsWith('Not enough time left', $tooLate);
    }
}
