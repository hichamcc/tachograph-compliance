<?php

namespace Tests\Unit\Tachograph\Normalization;

use App\Tachograph\Config\TachoConfig;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Normalization\IssueSeverity;
use App\Tachograph\Normalization\IssueType;
use App\Tachograph\Normalization\LocalJsonNormalizer;
use App\Tachograph\Normalization\NormalizationResult;
use PHPUnit\Framework\TestCase;

class LocalJsonNormalizerTest extends TestCase
{
    private function fixture(string $name): NormalizationResult
    {
        $payload = json_decode(file_get_contents(__DIR__.'/../../../Fixtures/activities/'.$name), true);

        return (new LocalJsonNormalizer(TachoConfig::defaults()))->normalize($payload);
    }

    public function test_happy_path_fixture(): void
    {
        $result = $this->fixture('week_happy_path.json');

        $this->assertSame(['test_driver_01'], $result->driverIds());
        $this->assertCount(13, $result->activities('test_driver_01'));
        $this->assertSame([], $result->issues());
        // BREAK input is not trusted as-is: classification is our job.
        $this->assertSame(ActivityType::REST, $result->activities()[3]->type);
        $this->assertSame('BREAK', $result->activities()[3]->rawStatus);
    }

    public function test_data_quality_fixture_reports_every_problem(): void
    {
        $result = $this->fixture('data_quality_issues.json');
        $types = array_map(fn ($i) => $i->type, $result->issues());

        $this->assertContains(IssueType::DUPLICATE_EVENT, $types);
        $this->assertContains(IssueType::MISSING_TIMESTAMP, $types);
        $this->assertContains(IssueType::END_BEFORE_START, $types);
        $this->assertContains(IssueType::INVALID_ACTIVITY_TYPE, $types);
        $this->assertContains(IssueType::INVALID_TIMESTAMP, $types);
        $this->assertContains(IssueType::MISSING_DRIVER_ID, $types);

        // Naive timestamps are rejected; explicit non-UTC offsets are converted with a warning.
        $tz = array_values(array_filter($result->issues(), fn ($i) => $i->type === IssueType::TIMEZONE_INCONSISTENCY));
        $this->assertSame(IssueSeverity::ERROR, $tz[0]->severity);
        $this->assertSame(IssueSeverity::WARNING, $tz[array_key_last($tz)]->severity);

        $converted = array_values(array_filter($result->activities('test_driver_02'), fn ($a) => $a->start->format('H:i') === '12:00'));
        $this->assertCount(1, $converted);
        $this->assertSame('UTC', $converted[0]->start->getTimezone()->getName());

        // driving, work (vehicle 2), rest, SLEEPING→UNKNOWN, +02:00 work
        $this->assertCount(5, $result->activities('test_driver_02'));
        $this->assertSame(11, $result->recordsRetrieved);
    }
}
