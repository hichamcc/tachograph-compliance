<?php

namespace Tests\Feature\Tachograph;

use App\Tachograph\Compliance\ComplianceEngine;
use App\Tachograph\Compliance\Finding;
use App\Tachograph\Compliance\FindingStatus;
use App\Tachograph\Config\TachoConfig;
use App\Tachograph\Data\Period;
use App\Tachograph\Normalization\LocalJsonNormalizer;
use App\Tachograph\Normalization\MaponActivityNormalizer;
use App\Tachograph\Normalization\TimelineBuilder;
use Tests\TestCase;

class ComplianceEngineTest extends TestCase
{
    private function rulesIn(array $findings): array
    {
        return array_values(array_unique(array_map(fn (Finding $f) => $f->rule, $findings)));
    }

    public function test_engine_runs_all_registered_rules_on_real_sample(): void
    {
        $config = app(TachoConfig::class);
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/mapon/daily_activities_sample.json')), true);
        $activities = (new MaponActivityNormalizer($config))->normalize('test_driver_01', [$payload])->activities();
        $timeline = (new TimelineBuilder($config))->build('test_driver_01', $activities, Period::fromStrings('2025-09-05T22:00:00Z', '2025-10-06T22:00:00Z'));

        $findings = app(ComplianceEngine::class)->evaluate($timeline, Period::fromStrings('2025-09-22T00:00:00Z', '2025-10-06T00:00:00Z'));

        $this->assertEqualsCanonicalizing([
            'BREAK_AFTER_4_5_HOURS', 'DAILY_DRIVING_LIMIT', 'EXTENDED_DAYS_PER_WEEK', 'WEEKLY_DRIVING_LIMIT',
            'TWO_WEEK_DRIVING_LIMIT', 'DAILY_REST', 'WEEKLY_REST', 'WEEKLY_REST_PATTERN',
        ], $this->rulesIn($findings));

        $violations = array_values(array_filter($findings, fn (Finding $f) => $f->isViolation()));
        $this->assertCount(1, $violations);
        $this->assertSame('DAILY_REST', $violations[0]->rule);
        $this->assertNotEmpty($violations[0]->relatedActivityIds);

        // Findings are sorted by period start.
        $starts = array_map(fn (Finding $f) => $f->periodStart->getTimestamp(), $findings);
        $sorted = $starts;
        sort($sorted);
        $this->assertSame($sorted, $starts);
    }

    public function test_happy_path_fixture_has_no_violations(): void
    {
        $config = app(TachoConfig::class);
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/activities/week_happy_path.json')), true);
        $activities = (new LocalJsonNormalizer($config))->normalize($payload)->activities('test_driver_01');
        $timeline = (new TimelineBuilder($config))->build('test_driver_01', $activities);

        $findings = app(ComplianceEngine::class)->evaluate($timeline, Period::fromStrings('2026-09-28 00:00', '2026-09-30 00:00'));
        $statuses = array_unique(array_map(fn (Finding $f) => $f->status, $findings), SORT_REGULAR);

        $this->assertNotContains(FindingStatus::VIOLATION, $statuses);
        $this->assertContains('FERRY_TRAIN', app(ComplianceEngine::class)->notEvaluated());
    }
}
