<?php

namespace Tests\Feature\Tachograph;

use App\Models\ActivityRecord;
use App\Models\Driver;
use App\Models\ProcessingRun;
use App\Models\RawPayload;
use App\Models\User;
use App\Models\ValidationIssue;
use App\RunStatus;
use App\RunType;
use App\Services\Tachograph\EvaluationService;
use App\Services\Tachograph\FetchService;
use App\Tachograph\Data\Period;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class FetchTest extends TestCase
{
    use RefreshDatabase;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Sleep::fake();
        config(['services.mapon.key' => 'test-key', 'services.mapon.base_url' => 'https://mapon.test/api/v1/']);
        $this->driver = Driver::create(['external_id' => '424242', 'origin' => 'mapon', 'display_name' => 'Test Driver']);
    }

    private function fakeActivities(): void
    {
        $sample = json_decode(file_get_contents(base_path('tests/Fixtures/mapon/daily_activities_sample.json')), true);

        Http::fake(['mapon.test/api/v1/driver/daily_activities.json*' => Http::response($sample)]);
    }

    public function test_fetch_and_evaluate_end_to_end(): void
    {
        $this->fakeActivities();

        $this->artisan('tacho:fetch', ['--driver' => ['424242'], '--start' => '2025-09-22', '--end' => '2025-10-05'])->assertSuccessful();

        // History window 2025-08-25 → 2025-10-07 split into 28-day chunks.
        $requests = Http::recorded()->map(fn ($pair) => urldecode($pair[0]->url()));
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('driver=424242', $requests[0]);
        $this->assertStringContainsString('from=2025-08-25T00:00:00Z', $requests[0]);
        $this->assertStringContainsString('till=2025-09-22T00:00:00Z', $requests[0]);
        $this->assertStringContainsString('from=2025-09-22T00:00:00Z', $requests[1]);
        $this->assertStringContainsString('till=2025-10-07T00:00:00Z', $requests[1]);

        $fetch = ProcessingRun::where('type', RunType::FETCH)->sole();
        $this->assertSame(RunStatus::DONE, $fetch->status);
        $this->assertSame(2, RawPayload::where('processing_run_id', $fetch->id)->count());
        $this->assertSame(220, ActivityRecord::where('driver_id', $this->driver->id)->count()); // chunk overlap deduplicated

        $evaluate = ProcessingRun::where('type', RunType::EVALUATE)->sole();
        $this->assertSame(RunStatus::DONE, $evaluate->status);
        $this->assertSame($fetch->batch_id, $evaluate->batch_id);

        $report = app(EvaluationService::class)->report($evaluate);
        $this->assertSame(1, $report->summary['confirmed_violations']);
        $this->assertSame('Test Driver', $report->driver['name']);
    }

    public function test_second_fetch_only_refreshes_recent_days(): void
    {
        $this->fakeActivities();
        config(['tachograph.history_days' => 7]);
        $service = app(FetchService::class);
        $period = app(EvaluationService::class)->reportPeriod('2025-10-01', '2025-10-05');

        $this->assertSame('2025-09-24', $service->fetchWindow($this->driver, $period)->start->format('Y-m-d'));

        $service->start($this->driver, $period);

        // Stored data now reaches back far enough: re-fetch from 3 days before the newest record.
        $window = $service->fetchWindow($this->driver, $period);
        $this->assertSame('2025-10-03 21:59', $window->start->format('Y-m-d H:i'));
        $this->assertSame('2025-10-07', $window->end->format('Y-m-d'));
    }

    public function test_chunks_never_exceed_the_mapon_limit(): void
    {
        $service = app(FetchService::class);
        $chunks = $service->chunks(app(EvaluationService::class)->reportPeriod('2026-01-01', '2026-03-31'));

        $this->assertCount(4, $chunks);
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(31 * 86400, $chunk->durationSeconds());
        }
        $this->assertSame('2026-04-01', end($chunks)->end->format('Y-m-d'));
    }

    public function test_permanent_mapon_error_fails_both_runs_with_a_safe_message(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 1015, 'msg' => 'Paid add-on required']])]);

        $this->artisan('tacho:fetch', ['--driver' => ['424242'], '--start' => '2025-09-22', '--end' => '2025-10-05'])->assertSuccessful();

        $fetch = ProcessingRun::where('type', RunType::FETCH)->sole();
        $evaluate = ProcessingRun::where('type', RunType::EVALUATE)->sole();

        $this->assertSame(RunStatus::FAILED, $fetch->status);
        $this->assertSame(RunStatus::FAILED, $evaluate->status);
        $this->assertStringContainsString('1015', $evaluate->error_message);
        $this->assertStringNotContainsString('test-key', $evaluate->error_message);
        Http::assertSentCount(1); // not retried, remaining chunk skipped
    }

    public function test_fetch_requires_drivers(): void
    {
        $this->artisan('tacho:fetch', ['--start' => '2025-09-22', '--end' => '2025-10-05'])->assertFailed();
        $this->artisan('tacho:fetch', ['--all' => true])->assertFailed(); // no period
    }

    public function test_all_option_uses_active_mapon_drivers_only(): void
    {
        $this->fakeActivities();
        Driver::create(['external_id' => '1', 'origin' => 'mapon', 'is_active' => false]);
        Driver::create(['external_id' => 'test_driver_01', 'origin' => 'local']);

        $this->artisan('tacho:fetch', ['--all' => true, '--since' => '2025-10-01', '--no-evaluate' => true])->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'driver=424242'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'driver=1&') || str_contains($r->url(), 'test_driver_01'));
        $this->assertSame(0, ProcessingRun::where('type', RunType::EVALUATE)->count());
    }

    public function test_prune_raw_deletes_old_payloads_and_files(): void
    {
        $old = RawPayload::store('driver/daily_activities', '[]');
        $old->update(['fetched_at' => now()->subDays(100)]);
        $new = RawPayload::store('driver/daily_activities', '[1]');

        $this->artisan('tacho:prune-raw', ['--days' => 90])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($new);
        Storage::disk('local')->assertMissing($old->payload_path);
        Storage::disk('local')->assertExists($new->payload_path);
    }

    public function test_temporary_mapon_outage_in_sync_mode_shows_a_failed_run_not_an_error_page(): void
    {
        Http::fake(['*' => Http::response('Bad gateway', 502)]);
        $this->be(User::factory()->create());

        $response = $this->post(route('tachograph.runs.store', $this->driver), ['start' => '2025-09-22', 'end' => '2025-09-28']);

        $run = ProcessingRun::where('type', RunType::EVALUATE)->sole();
        $response->assertRedirect(route('tachograph.runs.show', $run));
        $this->assertSame(RunStatus::FAILED, $run->fresh()->status);
        $this->assertStringContainsString('502', $run->fresh()->error_message);
        $this->assertStringNotContainsString('test-key', $run->fresh()->error_message);

        $this->get(route('tachograph.runs.show', $run))->assertOk()->assertSee('502');
    }

    public function test_raw_payloads_are_pruned_at_most_once_a_day(): void
    {
        $old = RawPayload::store('driver/daily_activities', '[]');
        $old->update(['fetched_at' => now()->subDays(100)]);

        RawPayload::pruneDaily();
        $this->assertModelMissing($old);

        $older = RawPayload::store('driver/daily_activities', '[2]');
        $older->update(['fetched_at' => now()->subDays(100)]);

        RawPayload::pruneDaily(); // already pruned today
        $this->assertModelExists($older);
    }

    public function test_re_download_replaces_stale_records_and_their_data_problems(): void
    {
        $t = fn (string $time) => strtotime("2025-10-09 {$time} UTC");
        $day = fn (array $activities) => [['day' => '2025-10-08T22:00:00Z', 'activities' => $activities]];
        $record = fn (string $status, string $source, string $from, string $to) => ['start' => $t($from), 'end' => $t($to), 'status' => $status, 'source' => $source, 'unitId' => 1];

        // Earlier record outside the re-downloaded span must survive.
        $older = [['day' => '2025-10-07T22:00:00Z', 'activities' => [
            ['start' => strtotime('2025-10-08 06:00 UTC'), 'end' => strtotime('2025-10-08 08:00 UTC'), 'status' => 'DRIVING', 'source' => 'ddd', 'unitId' => 1],
        ]]];

        // First download: driving until 16:29, then Mapon's gap filler.
        $first = $day([
            $record('DRIVING', 'ddd', '06:00', '16:03'),
            $record('WORK', 'can', '16:03', '16:29'),
            $record('REST', 'unkn', '16:29', '21:59'),
        ]);
        // Two hours later the card was downloaded: the CAN record is re-cut, the filler shrank.
        $second = $day([
            $record('DRIVING', 'ddd', '06:00', '16:03'),
            $record('WORK', 'ddd', '16:03', '16:50'),
            $record('REST', 'unkn', '16:50', '21:59'),
        ]);

        Http::fakeSequence('mapon.test/*')->push($older)->push($first)->push($second);
        $fetch = app(FetchService::class);
        $span = fn (string $from, string $to) => new Period(new \DateTimeImmutable($from), new \DateTimeImmutable($to));

        config(['tachograph.history_days' => 0]);
        $fetch->fetchNow($this->driver, $span('2025-10-08T00:00:00Z', '2025-10-08T23:00:00Z'), full: true);
        $fetch->fetchNow($this->driver, $span('2025-10-09T00:00:00Z', '2025-10-09T23:00:00Z'), full: true);
        $this->assertSame(4, ActivityRecord::where('driver_id', $this->driver->id)->count());
        $this->assertSame(0, ValidationIssue::whereNull('driver_id')->count(), 'issues belong to the driver, not stored twice');
        $this->assertSame(1, ValidationIssue::where('type', 'UNKNOWN_SOURCE_FILL')->count());

        $fetch->fetchNow($this->driver, $span('2025-10-09T00:00:00Z', '2025-10-09T23:00:00Z'), full: true);

        $rows = ActivityRecord::where('driver_id', $this->driver->id)->orderBy('start_at')->get();
        $this->assertSame(
            ['2025-10-08 06:00 DRIVING ddd', '2025-10-09 06:00 DRIVING ddd', '2025-10-09 16:03 WORK ddd', '2025-10-09 16:50 UNKNOWN unkn'],
            $rows->map(fn ($r) => $r->start_at->format('Y-m-d H:i').' '.$r->type.' '.$r->source)->all(),
        );

        // Only the current filler is reported, and the old CAN/ddd overlap is gone.
        $fillers = ValidationIssue::where('type', 'UNKNOWN_SOURCE_FILL')->get();
        $this->assertCount(1, $fillers);
        $this->assertSame('16:50', $fillers[0]->period_start->format('H:i'));
        $this->assertSame(0, ValidationIssue::where('type', 'OVERLAPPING_ACTIVITY')->count());
    }

    public function test_file_import_does_not_remove_existing_records(): void
    {
        $this->artisan('tacho:import-file', ['path' => 'tests/Fixtures/activities/week_happy_path.json'])->assertSuccessful();
        $count = ActivityRecord::count();

        $partial = tempnam(sys_get_temp_dir(), 'tacho');
        file_put_contents($partial, json_encode(['driver_id' => 'test_driver_01', 'activities' => [
            ['activity_type' => 'WORK', 'start_time' => '2026-09-28T06:00:00Z', 'end_time' => '2026-09-28T06:10:00Z'],
        ]]));
        $this->artisan('tacho:import-file', ['path' => $partial])->assertSuccessful();
        unlink($partial);

        $this->assertSame($count + 1, ActivityRecord::count());
    }
}
