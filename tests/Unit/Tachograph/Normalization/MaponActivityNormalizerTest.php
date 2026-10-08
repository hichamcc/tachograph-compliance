<?php

namespace Tests\Unit\Tachograph\Normalization;

use App\Tachograph\Config\TachoConfig;
use App\Tachograph\Data\ActivitySource;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Data\TachoEventType;
use App\Tachograph\Normalization\IssueType;
use App\Tachograph\Normalization\MaponActivityNormalizer;
use App\Tachograph\Normalization\NormalizationResult;
use App\Tachograph\Normalization\RawChunk;
use PHPUnit\Framework\TestCase;

class MaponActivityNormalizerTest extends TestCase
{
    private const T0 = 1790200800; // 2026-09-23T22:00:00Z

    private function normalize(array $activities, array $configOverrides = [], string $driver = 'test_driver_01'): NormalizationResult
    {
        $normalizer = new MaponActivityNormalizer(TachoConfig::defaults($configOverrides));

        return $normalizer->normalize($driver, [[['day' => '2026-09-24 00:00:00', 'activities' => $activities]]]);
    }

    private function record(string $status, int $start, int $end, string $source = 'ddd', ?int $duration = null): array
    {
        return ['start' => $start, 'end' => $end, 'duration' => $duration ?? $end - $start, 'status' => $status, 'source' => $source, 'unitId' => 900001];
    }

    private function issueTypes(NormalizationResult $result): array
    {
        return array_map(fn ($i) => $i->type, $result->issues());
    }

    public function test_maps_statuses_and_converts_timestamps_to_utc(): void
    {
        $result = $this->normalize([
            $this->record('DRIVING', self::T0, self::T0 + 3600),
            $this->record('WORK', self::T0 + 3600, self::T0 + 4000),
            $this->record('AVAILABLE', self::T0 + 4000, self::T0 + 5000),
            $this->record('REST', self::T0 + 5000, self::T0 + 9000),
        ]);

        $activities = $result->activities('test_driver_01');

        $this->assertSame(
            [ActivityType::DRIVING, ActivityType::WORK, ActivityType::AVAILABILITY, ActivityType::REST],
            array_map(fn ($a) => $a->type, $activities),
        );
        $this->assertSame('2026-09-23T22:00:00+00:00', $activities[0]->start->format(DATE_ATOM));
        $this->assertSame('900001', $activities[0]->vehicleId);
        $this->assertSame(ActivitySource::DDD, $activities[0]->source);
        $this->assertFalse($activities[0]->uncertain);
        $this->assertSame([], $result->issues());
    }

    public function test_unkn_rest_becomes_unknown_never_rest(): void
    {
        $result = $this->normalize([$this->record('REST', self::T0, self::T0 + 11 * 3600, 'unkn')]);

        $activity = $result->activities()[0];
        $this->assertSame(ActivityType::UNKNOWN, $activity->type);
        $this->assertTrue($activity->uncertain);
        $this->assertSame([IssueType::UNKNOWN_SOURCE_FILL], $this->issueTypes($result));
    }

    public function test_duration_is_computed_from_timestamps_not_mapon_duration(): void
    {
        $result = $this->normalize([$this->record('REST', self::T0, self::T0 + 7200, 'ddd', duration: 0)]);

        $this->assertSame(7200, $result->activities()[0]->durationSeconds());
    }

    public function test_zero_length_card_events_are_extracted_as_events(): void
    {
        $result = $this->normalize([
            $this->record('CARD_INSERTED', self::T0, self::T0),
            $this->record('DRIVING', self::T0, self::T0 + 600),
            $this->record('WORK_PERIOD_FINISHED', self::T0 + 600, self::T0 + 600),
        ]);

        $this->assertCount(1, $result->activities());
        $this->assertSame(
            [TachoEventType::CARD_INSERTED, TachoEventType::WORK_PERIOD_FINISHED],
            array_map(fn ($e) => $e->type, $result->events('test_driver_01')),
        );
    }

    public function test_chunk_overlap_is_deduplicated(): void
    {
        $day = ['day' => '2026-09-24 00:00:00', 'activities' => [$this->record('DRIVING', self::T0, self::T0 + 3600)]];
        $normalizer = new MaponActivityNormalizer(TachoConfig::defaults());

        $result = $normalizer->normalize('test_driver_01', [
            new RawChunk([$day], 1),
            new RawChunk(['data' => [$day]], 2), // with the "data" wrapper
        ]);

        $this->assertCount(1, $result->activities());
        $this->assertSame(1, $result->activities()[0]->rawPayloadId);
        $this->assertSame(2, $result->recordsRetrieved);
        $this->assertSame([IssueType::DUPLICATE_EVENT], $this->issueTypes($result));
    }

    public function test_missing_and_invalid_timestamps_are_rejected(): void
    {
        $result = $this->normalize([
            ['start' => self::T0, 'status' => 'DRIVING', 'source' => 'ddd'],
            ['start' => 'yesterday', 'end' => self::T0, 'status' => 'DRIVING', 'source' => 'ddd'],
            $this->record('DRIVING', self::T0 + 100, self::T0),
        ]);

        $this->assertSame([], $result->activities());
        $this->assertSame(3, $result->recordsInvalid);
        $this->assertSame(
            [IssueType::MISSING_TIMESTAMP, IssueType::INVALID_TIMESTAMP, IssueType::END_BEFORE_START],
            $this->issueTypes($result),
        );
    }

    public function test_unknown_status_becomes_unknown_with_issue(): void
    {
        $result = $this->normalize([$this->record('FERRY', self::T0, self::T0 + 3600)]);

        $this->assertSame(ActivityType::UNKNOWN, $result->activities()[0]->type);
        $this->assertSame([IssueType::INVALID_ACTIVITY_TYPE], $this->issueTypes($result));
    }

    public function test_can_source_is_marked_uncertain_by_default(): void
    {
        $result = $this->normalize([$this->record('DRIVING', self::T0, self::T0 + 3600, 'can')]);

        $this->assertTrue($result->activities()[0]->uncertain);
        $this->assertSame([IssueType::NON_TACHOGRAPH_SOURCE], $this->issueTypes($result));

        $certain = $this->normalize([$this->record('DRIVING', self::T0, self::T0 + 3600, 'can')], ['data' => ['treat_can_source_as' => 'certain']]);
        $this->assertFalse($certain->activities()[0]->uncertain);
    }

    public function test_rest_with_card_removed_follows_policy(): void
    {
        $records = [
            $this->record('CARD_REMOVED', self::T0, self::T0),
            $this->record('REST', self::T0, self::T0 + 10 * 3600),
            $this->record('CARD_INSERTED', self::T0 + 10 * 3600, self::T0 + 10 * 3600),
            $this->record('REST', self::T0 + 10 * 3600, self::T0 + 11 * 3600),
        ];

        $uncertain = $this->normalize($records)->activities();
        $this->assertTrue($uncertain[0]->uncertain);
        $this->assertFalse($uncertain[1]->uncertain);

        $unknown = $this->normalize($records, ['data' => ['treat_card_out_rest_as' => 'unknown']])->activities();
        $this->assertSame(ActivityType::UNKNOWN, $unknown[0]->type);

        $rest = $this->normalize($records, ['data' => ['treat_card_out_rest_as' => 'rest']])->activities();
        $this->assertFalse($rest[0]->uncertain);
        $this->assertSame(ActivityType::REST, $rest[0]->type);
    }

    public function test_missing_driver_id_is_rejected(): void
    {
        $result = $this->normalize([$this->record('DRIVING', self::T0, self::T0 + 60)], driver: '');

        $this->assertSame([IssueType::MISSING_DRIVER_ID], $this->issueTypes($result));
        $this->assertSame([], $result->activities());
    }

    public function test_real_sample_fixture_normalizes_cleanly(): void
    {
        $payload = json_decode(file_get_contents(__DIR__.'/../../../Fixtures/mapon/daily_activities_sample.json'), true);
        $result = (new MaponActivityNormalizer(TachoConfig::defaults()))->normalize('test_driver_01', [$payload]);

        $this->assertSame(220, $result->recordsRetrieved);
        $this->assertSame(0, $result->recordsInvalid);
        $this->assertSame(220, count($result->activities()));
        $this->assertSame(
            [IssueType::UNKNOWN_SOURCE_FILL],
            array_values(array_unique(array_map(fn ($i) => $i->type, $result->issues()), SORT_REGULAR)),
        );
    }
}
