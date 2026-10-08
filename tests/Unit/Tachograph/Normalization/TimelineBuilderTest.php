<?php

namespace Tests\Unit\Tachograph\Normalization;

use App\Tachograph\Config\TachoConfig;
use App\Tachograph\Data\ActivitySource;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Data\Period;
use App\Tachograph\Normalization\MaponActivityNormalizer;
use App\Tachograph\Normalization\TimelineBuilder;
use PHPUnit\Framework\TestCase;
use Tests\Support\TimelineFactory;

class TimelineBuilderTest extends TestCase
{
    private function types(array $activities): array
    {
        return array_map(fn ($a) => $a->type, $activities);
    }

    public function test_rest_classification_thresholds(): void
    {
        $timeline = TimelineFactory::driver()
            ->drive('1h')->rest('45m')
            ->drive('1h')->rest('8h59m')
            ->drive('1h')->rest('9h')
            ->drive('1h')->rest('23h59m')
            ->drive('1h')->rest('24h')
            ->drive('1h')
            ->build();

        $rests = array_values(array_filter($timeline->activities(), fn ($a) => $a->type->isRestLike()));

        $this->assertSame(
            [ActivityType::BREAK, ActivityType::BREAK, ActivityType::DAILY_REST, ActivityType::DAILY_REST, ActivityType::WEEKLY_REST],
            $this->types($rests),
        );
    }

    public function test_one_second_day_boundary_gap_is_closed_and_rest_merged(): void
    {
        // Mapon splits a rest at the day boundary: 22:00–23:59:59, 00:00–07:00 (1s gap).
        $timeline = TimelineFactory::driver()
            ->startAt('2026-09-28 16:00')->drive('6h')
            ->rest('1h59m59s')->gap('1s')->rest('9h')
            ->drive('1h')
            ->build();

        $this->assertSame([ActivityType::DRIVING, ActivityType::DAILY_REST, ActivityType::DRIVING], $this->types($timeline->activities()));
        $rest = $timeline->activities()[1];
        $this->assertSame(11 * 3600, $rest->durationSeconds());
        $this->assertCount(2, $rest->sourceEventIds);
    }

    public function test_gap_above_tolerance_becomes_unknown_never_rest(): void
    {
        $timeline = TimelineFactory::driver()->drive('4h')->gap('11h')->drive('4h')->build();

        $this->assertSame([ActivityType::DRIVING, ActivityType::UNKNOWN, ActivityType::DRIVING], $this->types($timeline->activities()));
        $this->assertTrue($timeline->activities()[1]->uncertain);
    }

    public function test_unknown_is_not_merged_into_rest(): void
    {
        $timeline = TimelineFactory::driver()->rest('5h')->unknown('6h')->drive('1h')->build();

        $this->assertSame([ActivityType::BREAK, ActivityType::UNKNOWN, ActivityType::DRIVING], $this->types($timeline->activities()));
    }

    public function test_ddd_wins_over_overlapping_can(): void
    {
        $ddd = TimelineFactory::driver()->startAt('2026-09-28 06:00')->drive('2h', ActivitySource::DDD)->activities();
        $can = TimelineFactory::driver()->startAt('2026-09-28 07:00')->work('2h', ActivitySource::CAN, uncertain: true)->activities();

        $timeline = (new TimelineBuilder(TachoConfig::defaults()))->build('test_driver_01', [...$ddd, ...$can]);
        $activities = $timeline->activities();

        $this->assertSame([ActivityType::DRIVING, ActivityType::WORK], $this->types($activities));
        $this->assertSame('08:00', $activities[0]->end->format('H:i'));
        $this->assertSame('08:00', $activities[1]->start->format('H:i'));
        $this->assertSame(ActivitySource::CAN, $activities[1]->source);
    }

    public function test_can_driving_beats_padded_card_rest_after_last_download(): void
    {
        // Card downloaded at 12:00; Mapon pads the rest of the day with REST/ddd while CAN shows driving.
        $card = TimelineFactory::driver()->startAt('2026-10-05 08:00')->drive('4h', ActivitySource::DDD)->rest('9h59m59s', ActivitySource::DDD)->activities();
        $can = TimelineFactory::driver()->startAt('2026-10-05 14:00')->drive('3h', ActivitySource::CAN, uncertain: true)->activities();

        $timeline = (new TimelineBuilder(TachoConfig::defaults()))->build('test_driver_01', [...$card, ...$can]);

        $this->assertSame([ActivityType::DRIVING, ActivityType::BREAK, ActivityType::DRIVING, ActivityType::BREAK], $this->types($timeline->activities()));
        $this->assertSame(7 * 3600, $timeline->secondsOf(ActivityType::DRIVING));
        $this->assertTrue($timeline->activities()[2]->uncertain);
        $this->assertSame(ActivitySource::CAN, $timeline->activities()[2]->source);
    }

    public function test_can_rest_does_not_override_card_driving(): void
    {
        $card = TimelineFactory::driver()->startAt('2026-10-05 08:00')->drive('4h', ActivitySource::DDD)->activities();
        $can = TimelineFactory::driver()->startAt('2026-10-05 08:00')->rest('4h', ActivitySource::CAN, uncertain: true)->activities();

        $timeline = (new TimelineBuilder(TachoConfig::defaults()))->build('test_driver_01', [...$card, ...$can]);

        $this->assertSame([ActivityType::DRIVING], $this->types($timeline->activities()));
        $this->assertSame(ActivitySource::DDD, $timeline->activities()[0]->source);
    }

    public function test_conflicting_ddd_records_become_unknown_for_the_overlap(): void
    {
        $a = TimelineFactory::driver()->startAt('2026-09-28 06:00')->drive('2h', ActivitySource::DDD)->activities();
        $b = TimelineFactory::driver()->startAt('2026-09-28 07:00')->rest('2h', ActivitySource::DDD)->activities();

        $timeline = (new TimelineBuilder(TachoConfig::defaults()))->build('test_driver_01', [...$a, ...$b]);

        $this->assertSame([ActivityType::DRIVING, ActivityType::UNKNOWN, ActivityType::BREAK], $this->types($timeline->activities()));
        $this->assertSame(3600, $timeline->activities()[1]->durationSeconds());
        $this->assertCount(2, $timeline->activities()[1]->sourceEventIds);
    }

    public function test_driving_on_different_vehicles_is_not_merged(): void
    {
        $factory = TimelineFactory::driver()->drive('1h');
        $factory->add(ActivityType::DRIVING, '1h', vehicleId: 'test_vehicle_02');

        $this->assertCount(2, $factory->build()->activities());
    }

    public function test_coverage_edges_are_filled_with_unknown(): void
    {
        $coverage = Period::fromStrings('2026-09-28 00:00', '2026-09-29 00:00');
        $timeline = TimelineFactory::driver()->startAt('2026-09-28 06:00')->drive('2h')->build(coverage: $coverage);

        $this->assertSame([ActivityType::UNKNOWN, ActivityType::DRIVING, ActivityType::UNKNOWN], $this->types($timeline->activities()));
        $this->assertSame(22 * 3600, $timeline->secondsOf(ActivityType::UNKNOWN));
        $this->assertSame(86400, array_sum($timeline->totals()));
    }

    public function test_timeline_totals_within_period_are_clipped(): void
    {
        $timeline = TimelineFactory::driver()->startAt('2026-09-28 22:00')->drive('4h')->build();

        $this->assertSame(2 * 3600, $timeline->secondsOf(ActivityType::DRIVING, Period::fromStrings('2026-09-28 00:00', '2026-09-29 00:00')));
    }

    public function test_real_sample_builds_contiguous_timeline(): void
    {
        $config = TachoConfig::defaults();
        $payload = json_decode(file_get_contents(__DIR__.'/../../../Fixtures/mapon/daily_activities_sample.json'), true);
        $result = (new MaponActivityNormalizer($config))->normalize('test_driver_01', [$payload]);
        $timeline = (new TimelineBuilder($config))->build('test_driver_01', $result->activities('test_driver_01'));

        $activities = $timeline->activities();
        for ($i = 1; $i < count($activities); $i++) {
            $this->assertEquals($activities[$i - 1]->end, $activities[$i]->start, "Gap/overlap before activity #{$i}");
        }

        $this->assertNotContains(ActivityType::REST, $this->types($activities), 'All REST must be classified');
        $this->assertContains(ActivityType::DAILY_REST, $this->types($activities));
        $this->assertLessThan(count($result->activities()), count($activities), 'Day-boundary rests should merge');
    }
}
