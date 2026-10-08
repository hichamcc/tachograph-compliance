<?php

namespace Tests\Unit\Tachograph\Normalization;

use App\Tachograph\Config\TachoConfig;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Normalization\DataValidator;
use App\Tachograph\Normalization\IssueType;
use PHPUnit\Framework\TestCase;
use Tests\Support\TimelineFactory;

class DataValidatorTest extends TestCase
{
    private function validate(array $activities): array
    {
        return array_map(fn ($i) => $i->type, (new DataValidator(TachoConfig::defaults()))->validate($activities));
    }

    public function test_contiguous_timeline_has_no_issues(): void
    {
        $activities = TimelineFactory::driver()->drive('4h')->rest('45m')->drive('2h')->activities();

        $this->assertSame([], $this->validate($activities));
    }

    public function test_gap_above_tolerance_is_reported(): void
    {
        $activities = TimelineFactory::driver()->drive('1h')->gap('2h')->drive('1h')->activities();

        $this->assertSame([IssueType::TIMELINE_GAP], $this->validate($activities));
    }

    public function test_gap_within_tolerance_is_ignored(): void
    {
        $activities = TimelineFactory::driver()->rest('8h')->gap('1s')->rest('8h')->activities();

        $this->assertSame([], $this->validate($activities));
    }

    public function test_overlap_on_two_vehicles(): void
    {
        $a = TimelineFactory::driver()->startAt('2026-09-28 06:00')->drive('2h')->activities();
        $b = TimelineFactory::driver()->startAt('2026-09-28 07:00')->add(ActivityType::WORK, '2h', vehicleId: 'test_vehicle_02')->activities();

        $this->assertSame(
            [IssueType::OVERLAPPING_ACTIVITY, IssueType::INCONSISTENT_ASSOCIATION],
            $this->validate([...$a, ...$b]),
        );
    }

    public function test_activity_inside_a_longer_one_does_not_report_false_gap(): void
    {
        $long = TimelineFactory::driver()->startAt('2026-09-28 06:00')->rest('10h')->activities();
        $inner = TimelineFactory::driver()->startAt('2026-09-28 07:00')->work('1h')->activities();
        $after = TimelineFactory::driver()->startAt('2026-09-28 16:00')->drive('1h')->activities();

        $this->assertSame([IssueType::OVERLAPPING_ACTIVITY], $this->validate([...$long, ...$inner, ...$after]));
    }

    public function test_gap_filler_overlapped_by_real_data_is_not_an_overlap(): void
    {
        $filler = TimelineFactory::driver()->startAt('2026-09-28 18:00')->unknown('4h')->activities();
        $real = TimelineFactory::driver()->startAt('2026-09-28 18:00')->rest('11h', \App\Tachograph\Data\ActivitySource::DDD)->activities();

        $this->assertSame([], $this->validate([...$filler, ...$real]));
    }
}
