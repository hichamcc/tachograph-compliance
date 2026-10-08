<?php

namespace Tests\Feature\Tachograph;

use App\Models\ComplianceFinding;
use App\Models\Driver;
use App\Models\ProcessingRun;
use App\Models\User;
use App\RunStatus;
use App\RunType;
use App\Services\Tachograph\EvaluationService;
use App\Tachograph\Reporting\JsonReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function evaluatedRun(string $fixture = 'tests/Fixtures/mapon/daily_activities_sample.json', string $start = '2025-09-22', string $end = '2025-10-05'): ProcessingRun
    {
        $this->artisan('tacho:import-file', ['path' => $fixture, '--format' => 'mapon', '--driver' => 'test_driver_01'])->assertSuccessful();

        $service = app(EvaluationService::class);

        return $service->evaluate(Driver::where('external_id', 'test_driver_01')->sole(), $service->reportPeriod($start, $end));
    }

    public function test_evaluation_persists_findings_and_snapshot(): void
    {
        $run = $this->evaluatedRun();

        $this->assertSame(RunType::EVALUATE, $run->type);
        $this->assertSame(RunStatus::DONE, $run->status);
        $this->assertSame($run->findings_count, ComplianceFinding::where('processing_run_id', $run->id)->count());
        $this->assertSame(1, ComplianceFinding::where('processing_run_id', $run->id)->where('status', 'VIOLATION')->count());
        Storage::disk('local')->assertExists("reports/{$run->id}.json");
    }

    public function test_json_report_matches_snapshot(): void
    {
        $run = $this->evaluatedRun();
        $report = app(EvaluationService::class)->report($run);

        $json = app(JsonReport::class)->toArray($report);
        unset($json['processing_id'], $json['generated_at']);

        $snapshot = base_path('tests/Fixtures/reports/sample_2025-W39-W40.json');
        if (! file_exists($snapshot)) {
            file_put_contents($snapshot, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->markTestIncomplete('Snapshot created; review and commit it.');
        }

        $this->assertSame(json_decode(file_get_contents($snapshot), true), json_decode(json_encode($json), true));
    }

    public function test_report_pages_require_authentication(): void
    {
        $run = $this->evaluatedRun();

        $this->get(route('tachograph.reports.show', $run))->assertRedirect(route('login'));
        $this->get(route('tachograph.reports.export', [$run, 'json']))->assertRedirect(route('login'));
        $this->get(route('tachograph.reports.export', [$run, 'csv', 'findings']))->assertRedirect(route('login'));
    }

    public function test_html_report_renders_in_app(): void
    {
        $run = $this->evaluatedRun();
        $this->be(User::factory()->create());

        $this->get(route('tachograph.reports.show', $run))
            ->assertOk()
            ->assertSee('Tachograph report')
            ->assertSee('Violations')
            ->assertSee('No daily rest within 24h after the previous rest')
            ->assertSee('Not an official legal determination');
    }

    public function test_exports_have_correct_content_types(): void
    {
        $run = $this->evaluatedRun();
        $this->be(User::factory()->create());

        $json = $this->get(route('tachograph.reports.export', [$run, 'json']))->assertOk();
        $this->assertStringContainsString('application/json', $json->headers->get('Content-Type'));
        $this->assertSame(1, $json->json('summary.violations'));
        $this->assertSame('DAILY_REST', $json->json('violations.0.rule'));

        foreach (['findings', 'daily', 'weekly', 'activities'] as $kind) {
            $csv = $this->get(route('tachograph.reports.export', [$run, 'csv', $kind]))->assertOk();
            $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
            $this->assertStringContainsString('attachment', $csv->headers->get('Content-Disposition'));
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->streamedContent());
        }

        $html = $this->get(route('tachograph.reports.export', [$run, 'html']))->assertOk();
        $this->assertStringContainsString('text/html', $html->headers->get('Content-Type'));
        $this->assertStringContainsString('<!DOCTYPE html>', $html->getContent());

        $this->get(route('tachograph.reports.export', [$run, 'csv', 'secrets']))->assertNotFound();
        $this->get(route('tachograph.reports.export', [$run, 'pdf']))->assertNotFound();
    }

    public function test_run_without_report_returns_404(): void
    {
        $this->be(User::factory()->create());
        $run = ProcessingRun::create(['type' => RunType::FETCH, 'status' => RunStatus::DONE]);

        $this->get(route('tachograph.reports.show', $run))->assertNotFound();
    }

    public function test_evaluate_command_writes_requested_formats(): void
    {
        $this->artisan('tacho:import-file', ['path' => 'tests/Fixtures/activities/week_happy_path.json'])->assertSuccessful();
        $dir = sys_get_temp_dir().'/tacho_'.uniqid();

        $this->artisan('tacho:evaluate', [
            '--driver' => 'test_driver_01', '--start' => '2026-09-28', '--end' => '2026-09-29',
            '--output' => "{$dir}/report.json", '--format' => 'json,csv,html',
        ])->assertSuccessful();

        foreach (['report.json', 'report_findings.csv', 'report_daily.csv', 'report_weekly.csv', 'report_activities.csv', 'report.html'] as $file) {
            $this->assertFileExists("{$dir}/{$file}");
        }

        $json = json_decode(file_get_contents("{$dir}/report.json"), true);
        $this->assertSame(0, $json['summary']['violations']);
        $this->assertSame('2026-09-30T00:00:00Z', $json['period']['end']);
        $this->assertCount(2, $json['daily']);

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    }

    public function test_evaluate_command_validates_input(): void
    {
        $this->artisan('tacho:evaluate', ['--driver' => 'nobody', '--start' => '2026-09-28', '--end' => '2026-09-29'])->assertFailed();
        $this->artisan('tacho:evaluate', ['--driver' => 'x', '--start' => '2026-09-28', '--end' => '2026-09-29', '--format' => 'pdf'])->assertFailed();
    }

    public function test_csv_cells_are_protected_against_formula_injection(): void
    {
        $csv = new \App\Tachograph\Reporting\CsvReport;
        $report = new \App\Tachograph\Reporting\ReportData('x', '2026-01-01T00:00:00Z', ['id' => '=cmd', 'name' => null], ['start' => '2026-01-01T00:00:00Z', 'end' => '2026-01-02T00:00:00Z'], 'UTC', [], [], [], [], [], [], [[
            'type' => 'DRIVING', 'start' => 'a', 'end' => 'b', 'duration_hours' => -1, 'source' => 'ddd', 'uncertain' => false, 'vehicle_id' => '=HYPERLINK("x")', 'source_event_ids' => [],
        ]]);

        $out = $csv->render('activities', $report);

        $this->assertStringContainsString("'=HYPERLINK", $out);
        $this->assertStringContainsString(',-1,', $out); // negative numbers stay numeric
    }

    public function test_gap_filler_superseded_by_card_data_is_not_reported(): void
    {
        $t0 = 1790200800; // 2026-09-23T22:00:00Z
        $payload = [[
            'day' => '2026-09-24', 'activities' => [
                ['start' => $t0, 'end' => $t0 + 4 * 3600, 'status' => 'DRIVING', 'source' => 'ddd', 'unitId' => 1],
                ['start' => $t0 + 4 * 3600, 'end' => $t0 + 20 * 3600, 'status' => 'REST', 'source' => 'ddd', 'unitId' => 1],
                ['start' => $t0 + 4 * 3600, 'end' => $t0 + 8 * 3600, 'status' => 'REST', 'source' => 'unkn', 'unitId' => 1],
                ['start' => $t0 + 20 * 3600, 'end' => $t0 + 22 * 3600, 'status' => 'REST', 'source' => 'unkn', 'unitId' => 1],
            ],
        ]];
        $path = tempnam(sys_get_temp_dir(), 'tacho');
        file_put_contents($path, json_encode($payload));
        $this->artisan('tacho:import-file', ['path' => $path, '--format' => 'mapon', '--driver' => 'test_driver_01'])->assertSuccessful();
        unlink($path);

        $service = app(EvaluationService::class);
        $run = $service->evaluate(Driver::where('external_id', 'test_driver_01')->sole(), $service->reportPeriod('2026-09-23', '2026-09-24'));
        $issues = $service->report($run)->dataQuality;

        // The superseded filler (covered by ddd rest) is dropped; the real gap at the end stays.
        $fillers = array_values(array_filter($issues, fn ($i) => $i['type'] === 'UNKNOWN_SOURCE_FILL'));
        $this->assertCount(1, $fillers);
        $this->assertSame('2026-09-24T18:00:00Z', $fillers[0]['period_start']);
        $this->assertEmpty(array_filter($issues, fn ($i) => $i['type'] === 'OVERLAPPING_ACTIVITY'));
    }

    public function test_timeline_is_hidden_unless_enabled(): void
    {
        $run = $this->evaluatedRun();
        $this->be(User::factory()->create());

        $this->get(route('tachograph.reports.show', $run))->assertOk()->assertDontSee('tc-track');

        config(['tachograph.show_timeline' => true]);
        $this->get(route('tachograph.reports.show', $run))->assertOk()->assertSee('Timeline')->assertSee('tc-track');
    }

    public function test_findings_filters_in_app_only(): void
    {
        $run = $this->evaluatedRun();
        $this->be(User::factory()->create());

        $this->get(route('tachograph.reports.show', $run))
            ->assertOk()
            ->assertSee('Filter findings')
            ->assertSee("shows('violation', 'daily_rest')", false)
            ->assertSee("shows('warning', 'daily_driving')", false)
            ->assertSee('All rules');

        $html = $this->get(route('tachograph.reports.export', [$run, 'html']))->getContent();
        $this->assertStringNotContainsString('Filter findings', $html);
    }

    public function test_week_navigation(): void
    {
        $this->travelTo(new \DateTimeImmutable('2025-10-08 12:00:00'));
        $this->artisan('tacho:import-file', ['path' => 'tests/Fixtures/mapon/daily_activities_sample.json', '--format' => 'mapon', '--driver' => 'test_driver_01'])->assertSuccessful();
        $service = app(EvaluationService::class);
        $driver = Driver::where('external_id', 'test_driver_01')->sole();

        $week39 = $service->evaluate($driver, $service->reportPeriod('2025-09-22', '2025-09-28'));
        $week40 = $service->evaluate($driver, $service->reportPeriod('2025-09-29', '2025-10-05'));
        $twoWeeks = $service->evaluate($driver, $service->reportPeriod('2025-09-22', '2025-10-05'));
        $this->be(User::factory()->create());

        // Week 40: previous week has a report; next week (41) has none, so no button for it.
        $this->get(route('tachograph.reports.show', $week40))
            ->assertOk()
            ->assertSee(route('tachograph.reports.show', $week39))
            ->assertSee('Week 39')
            ->assertDontSee('Week 41')
            ->assertDontSee(route('tachograph.runs.store', $driver));

        // Week 39 links forward to week 40.
        $this->get(route('tachograph.reports.show', $week39))->assertSee(route('tachograph.reports.show', $week40));

        // Not a fixed week: no navigation.
        $this->get(route('tachograph.reports.show', $twoWeeks))->assertOk()->assertDontSee('Other weeks');
    }
}
