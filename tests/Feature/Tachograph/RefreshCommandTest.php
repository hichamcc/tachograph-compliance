<?php

namespace Tests\Feature\Tachograph;

use App\Models\Driver;
use App\Models\ProcessingRun;
use App\Models\User;
use App\RunStatus;
use App\RunType;
use App\Services\Tachograph\RefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class RefreshCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Sleep::fake();
        config(['services.mapon.key' => 'k', 'services.mapon.base_url' => 'https://mapon.test/api/v1/']);
        $this->travelTo(new \DateTimeImmutable('2025-10-07 10:00:00', new \DateTimeZone('UTC'))); // Tuesday, week 41

    }

    private function fakeMapon(): void
    {
        $sample = json_decode(file_get_contents(base_path('tests/Fixtures/mapon/daily_activities_sample.json')), true);

        Http::fake(function (Request $request) use ($sample) {
            $url = urldecode($request->url());

            return match (true) {
                str_contains($url, 'driver/list') => Http::response(['data' => ['drivers' => [
                    ['id' => 424242, 'name' => 'Active', 'surname' => 'Driver'],
                    ['id' => 555, 'name' => 'Idle', 'surname' => 'Driver'],
                ]]]),
                str_contains($url, 'unit/list') => Http::response(['data' => ['units' => []]]),
                str_contains($url, 'driver=424242') => Http::response($sample),
                default => Http::response([]), // no activity for the idle driver
            };
        });
    }

    private function activityRequests(string $driverId): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'daily_activities') && str_contains($r->url(), "driver={$driverId}"))->count();
    }

    public function test_refresh_downloads_all_drivers_and_checks_active_ones(): void
    {
        $this->fakeMapon();
        $this->artisan('tacho:refresh')->assertSuccessful();

        $active = Driver::where('external_id', '424242')->sole();
        $idle = Driver::where('external_id', '555')->sole();

        $this->assertTrue($active->isRecentlyActive());
        $this->assertFalse($idle->isRecentlyActive());
        $this->assertNotNull($idle->last_fetched_at);

        // Early in the week: the previous week (40) and the current week (41) are checked.
        $checks = ProcessingRun::where('type', RunType::EVALUATE)->where('driver_id', $active->id)->orderBy('period_start')->get();
        $this->assertSame(['2025-09-29', '2025-10-06'], $checks->map(fn ($r) => $r->period_start->format('Y-m-d'))->all());
        $this->assertTrue($checks->every(fn ($r) => $r->status === RunStatus::DONE));
        $this->assertSame(0, ProcessingRun::where('type', RunType::EVALUATE)->where('driver_id', $idle->id)->count());

        // History: at least 4 weeks before the first checked week were requested.
        $this->assertStringContainsString('from=2025-09-01T00:00:00Z', urldecode(Http::recorded(fn ($r) => str_contains($r->url(), 'driver=424242'))->first()[0]->url()));
    }

    public function test_second_refresh_replaces_reports_and_skips_idle_drivers(): void
    {
        $this->fakeMapon();
        $this->artisan('tacho:refresh')->assertSuccessful();
        $first = ProcessingRun::where('type', RunType::EVALUATE)->pluck('id')->all();
        $idleRequests = $this->activityRequests('555');

        $this->travel(2)->hours();
        $this->artisan('tacho:refresh')->assertSuccessful();

        $second = ProcessingRun::where('type', RunType::EVALUATE)->pluck('id')->all();
        $this->assertCount(2, $second, 'one report per week, replaced in place');
        $this->assertEmpty(array_intersect($first, $second));
        Storage::disk('local')->assertMissing("reports/{$first[0]}.json");

        $this->assertSame($idleRequests, $this->activityRequests('555'), 'idle driver refreshed at most daily');

        $this->travel(25)->hours();
        $this->artisan('tacho:refresh')->assertSuccessful();
        $this->assertGreaterThan($idleRequests, $this->activityRequests('555'));
    }

    public function test_manual_checks_are_kept(): void
    {
        $this->fakeMapon();
        $this->artisan('tacho:refresh')->assertSuccessful();
        $driver = Driver::where('external_id', '424242')->sole();
        $this->be(User::factory()->create());
        $this->post(route('tachograph.runs.store', $driver), ['start' => '2025-10-06', 'end' => '2025-10-07']);
        $manual = ProcessingRun::whereNotNull('user_id')->where('type', RunType::EVALUATE)->sole();

        $this->artisan('tacho:refresh')->assertSuccessful();

        $this->assertModelExists($manual);
    }

    public function test_overlapping_runs_are_skipped(): void
    {
        $this->fakeMapon();
        $lock = Cache::lock('tachograph.refresh', 60);
        $lock->get();

        $this->artisan('tacho:refresh')->expectsOutputToContain('already running')->assertSuccessful();
        Http::assertNothingSent();

        $lock->release();
    }

    public function test_mapon_failure_for_one_driver_does_not_stop_the_others(): void
    {
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            return match (true) {
                str_contains($url, 'driver/list') => Http::response(['data' => ['drivers' => [['id' => 1], ['id' => 2]]]]),
                str_contains($url, 'unit/list') => Http::response(['data' => ['units' => []]]),
                str_contains($url, 'driver=1&') || str_ends_with($url, 'driver=1') => Http::response(['error' => ['code' => 1015, 'msg' => 'Add-on']]),
                default => Http::response([]),
            };
        });

        $this->artisan('tacho:refresh')->assertSuccessful();

        $this->assertNull(Driver::where('external_id', '1')->sole()->last_fetched_at);
        $this->assertNotNull(Driver::where('external_id', '2')->sole()->last_fetched_at);
        $this->assertSame(RunStatus::FAILED, ProcessingRun::where('type', RunType::FETCH)->where('driver_id', Driver::where('external_id', '1')->value('id'))->sole()->status);
    }

    private const TOKEN = 'test-cron-token-0123456789abcdef0123456789';

    public function test_cron_url_is_disabled_without_a_token_and_hides_wrong_tokens(): void
    {
        $this->fakeMapon();

        config(['tachograph.cron_token' => '']);
        $this->get('/cron/refresh/anything')->assertNotFound();

        config(['tachograph.cron_token' => self::TOKEN]);
        $this->get('/cron/refresh/wrong-token-0123456789abcdef0123456789')->assertNotFound();
        $this->get('/cron/refresh/'.substr(self::TOKEN, 0, -1))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_cron_url_refreshes_due_drivers_and_returns_counts_only(): void
    {
        $this->fakeMapon();
        config(['tachograph.cron_token' => self::TOKEN]);

        $response = $this->get('/cron/refresh/'.self::TOKEN)->assertOk();

        $response->assertJson(['status' => 'ok', 'refreshed' => 2, 'remaining' => 0, 'synced' => true]);
        $this->assertSame(['status', 'refreshed', 'checked', 'failed', 'remaining', 'seconds', 'synced'], array_keys($response->json()));
        $this->assertSame(2, ProcessingRun::where('type', RunType::EVALUATE)->count());

        // Nothing due right after: the next call does no Mapon requests.
        $before = Http::recorded()->count();
        $this->get('/cron/refresh/'.self::TOKEN)->assertJson(['refreshed' => 0, 'remaining' => 0]);
        $this->assertSame($before, Http::recorded()->count());
    }

    public function test_time_budget_spreads_work_over_several_calls(): void
    {
        $this->fakeMapon();
        $service = app(RefreshService::class);

        $first = $service->run(budgetSeconds: 0); // budget already used up: only the sync happens
        $this->assertSame(0, $first['refreshed']);
        $this->assertSame(2, $first['remaining']);

        $second = $service->run(budgetSeconds: 60);
        $this->assertSame(2, $second['refreshed']);
        $this->assertSame(0, $second['remaining']);

        // Two hours later the active driver is due again, the idle one is not.
        $this->travel(2)->hours();
        $this->assertSame(['424242'], $service->dueDrivers()->pluck('external_id')->all());
    }

    public function test_cron_url_reports_busy_while_another_refresh_runs(): void
    {
        $this->fakeMapon();
        config(['tachograph.cron_token' => self::TOKEN]);
        $lock = Cache::lock('tachograph.refresh', 60);
        $lock->get();

        $this->get('/cron/refresh/'.self::TOKEN)->assertOk()->assertJson(['status' => 'busy']);

        $lock->release();
    }
}
