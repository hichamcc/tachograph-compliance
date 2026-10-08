<?php

namespace Tests\Unit\Tachograph\Reporting;

use App\Tachograph\Reporting\DayChart;
use App\Tachograph\Reporting\ReportData;
use PHPUnit\Framework\TestCase;

class DayChartTest extends TestCase
{
    private function report(array $activities, array $findings = [], string $tz = 'Europe/Copenhagen'): ReportData
    {
        return new ReportData('x', '2026-10-08T00:00:00Z', ['id' => 'd', 'name' => null],
            ['start' => '2026-09-28T00:00:00Z', 'end' => '2026-09-30T00:00:00Z'], $tz, [], [], [], $findings, [], [], $activities);
    }

    private function activity(string $type, string $start, string $end, string $source = 'ddd'): array
    {
        return ['type' => $type, 'start' => $start, 'end' => $end, 'duration_hours' => 0, 'source' => $source, 'uncertain' => false, 'vehicle_id' => null, 'source_event_ids' => []];
    }

    public function test_one_row_per_report_day_with_local_positions(): void
    {
        // 06:00–10:00 UTC = 08:00–12:00 Copenhagen (UTC+2).
        $rows = DayChart::rows($this->report([
            $this->activity('DRIVING', '2026-09-28T06:00:00Z', '2026-09-28T10:00:00Z'),
            $this->activity('DAILY_REST', '2026-09-28T10:00:00Z', '2026-09-30T00:00:00Z'),
        ]));

        $this->assertSame(['Mon 28 Sep', 'Tue 29 Sep'], array_column($rows, 'date'));
        $drive = $rows[0]['segments'][0];
        $this->assertSame('drive', $drive['category']);
        $this->assertEqualsWithDelta(8 / 24 * 100, $drive['left'], 0.01);
        $this->assertEqualsWithDelta(4 / 24 * 100, $drive['width'], 0.01);
        $this->assertSame(4.0, $rows[0]['driving_hours']);
        $this->assertSame('rest', $rows[1]['segments'][0]['category']);
    }

    public function test_trailing_offset_hours_shown_only_with_activity(): void
    {
        $rows = DayChart::rows($this->report([
            $this->activity('DAILY_REST', '2026-09-28T00:00:00Z', '2026-09-29T22:30:00Z'),
            $this->activity('DRIVING', '2026-09-29T22:30:00Z', '2026-09-30T00:00:00Z'), // 00:30–02:00 local on Wed
        ]));

        $this->assertSame(['Mon 28 Sep', 'Tue 29 Sep', 'Wed 30 Sep'], array_column($rows, 'date'));
        $this->assertSame(1.5, $rows[2]['driving_hours']);
    }

    public function test_shift_level_violations_are_marked(): void
    {
        $finding = fn (string $rule, string $certainty) => [
            'rule' => $rule, 'status' => 'VIOLATION', 'certainty' => $certainty, 'period_start' => '2026-09-28T06:00:00Z',
            'period_end' => '2026-09-28T12:00:00Z', 'measured_value' => 5.0, 'allowed_value' => 4.5, 'unit' => 'hours',
            'message' => '', 'related_activity_ids' => [], 'severity' => 'HIGH', 'details' => [],
        ];

        $rows = DayChart::rows($this->report([], [
            $finding('BREAK_AFTER_4_5_HOURS', 'POTENTIAL'),
            $finding('WEEKLY_DRIVING_LIMIT', 'CONFIRMED'), // weekly: not drawn on the timeline
        ]));

        $this->assertCount(1, $rows[0]['markers']);
        $this->assertTrue($rows[0]['markers'][0]['potential']);
        $this->assertSame('5h00 / 4h30', $rows[0]['markers'][0]['value']);
    }
}
