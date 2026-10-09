<?php

namespace Tests\Feature\Tachograph;

use App\Models\ComplianceFinding;
use App\Models\Driver;
use App\Models\ProcessingRun;
use App\Models\User;
use App\RunStatus;
use App\RunType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TachographUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->artisan('tacho:import-file', ['path' => 'tests/Fixtures/activities/week_happy_path.json'])->assertSuccessful();
    }

    private function driver(): Driver
    {
        return Driver::where('external_id', 'test_driver_01')->sole();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('tachograph.drivers.index'))->assertRedirect(route('login'));
        $this->get(route('tachograph.drivers.show', $this->driver()))->assertRedirect(route('login'));
        $this->post(route('tachograph.runs.store', $this->driver()))->assertRedirect(route('login'));
        $this->post(route('tachograph.drivers.sync'))->assertRedirect(route('login'));
    }

    public function test_driver_list_and_search(): void
    {
        Driver::create(['external_id' => '555', 'origin' => 'mapon', 'display_name' => 'Somebody Else', 'last_active_at' => now()]);
        $this->be(User::factory()->create());

        $this->get(route('tachograph.drivers.index'))->assertOk()->assertSee('test_driver_01')->assertSee('Somebody Else')->assertSee('Tachograph');
        $this->get(route('tachograph.drivers.index', ['q' => 'Somebody']))->assertOk()->assertSee('Somebody Else')->assertDontSee('test_driver_01');
    }

    public function test_sync_button(): void
    {
        config(['services.mapon.key' => 'k', 'services.mapon.base_url' => 'https://mapon.test/api/v1/']);
        Http::fake([
            '*driver/list.json*' => Http::response(['data' => ['drivers' => [['id' => 7, 'name' => 'New', 'surname' => 'Driver']]]]),
            '*unit/list.json*' => Http::response(['data' => ['units' => []]]),
        ]);
        $this->be(User::factory()->create());

        $this->from(route('tachograph.drivers.index'))
            ->post(route('tachograph.drivers.sync'))
            ->assertRedirect(route('tachograph.drivers.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('drivers', ['external_id' => '7', 'display_name' => 'New Driver']);
    }

    public function test_evaluate_from_driver_page_then_view_report(): void
    {
        $this->be(User::factory()->create());

        $this->get(route('tachograph.drivers.show', $this->driver()))->assertOk()->assertSee('Run check');

        $response = $this->post(route('tachograph.runs.store', $this->driver()), ['start' => '2026-09-28', 'end' => '2026-09-29']);

        $run = ProcessingRun::where('type', RunType::EVALUATE)->sole();
        $response->assertRedirect(route('tachograph.runs.show', $run));
        $this->assertSame(RunStatus::DONE, $run->status);

        $this->get(route('tachograph.runs.show', $run))->assertOk()->assertSee('View report')->assertDontSee('$ajax', false);
        $this->get(route('tachograph.reports.show', $run))->assertOk()->assertSee('No violations.');
        $this->get(route('tachograph.drivers.index'))->assertSee(route('tachograph.reports.show', $run));
    }

    public function test_pending_run_page_polls(): void
    {
        $this->be(User::factory()->create());
        $run = ProcessingRun::create(['type' => RunType::EVALUATE, 'status' => RunStatus::PENDING, 'driver_id' => $this->driver()->id]);

        $this->get(route('tachograph.runs.show', $run))->assertOk()->assertSee('$ajax', false)->assertSee('Waiting in the queue');
    }

    public function test_period_validation(): void
    {
        $this->be(User::factory()->create());
        $url = route('tachograph.runs.store', $this->driver());

        $this->post($url, ['start' => '2026-09-29', 'end' => '2026-09-28'])->assertSessionHasErrors('end');
        $this->post($url, ['start' => '2026-01-01', 'end' => now()->addDay()->toDateString()])->assertSessionHasErrors('end');
        $this->post($url, ['start' => '2026-01-01', 'end' => '2026-06-01'])->assertSessionHasErrors('end');
        $this->post($url, ['start' => 'yesterday', 'end' => '2026-06-01'])->assertSessionHasErrors('start');

        $this->assertSame(0, ProcessingRun::where('type', RunType::EVALUATE)->count());
    }

    public function test_import_upload_is_disabled_by_default(): void
    {
        config(['tachograph.allow_import' => false]);
        $this->be(User::factory()->create());

        $this->get(route('tachograph.drivers.index'))->assertDontSee('Import a JSON file');
        $this->post(route('tachograph.import'), [])->assertNotFound();
    }

    public function test_import_upload(): void
    {
        config(['tachograph.allow_import' => true]);
        $this->be(User::factory()->create());

        $file = UploadedFile::fake()->createWithContent('sample.json', file_get_contents(base_path('tests/Fixtures/mapon/daily_activities_sample.json')));

        $this->post(route('tachograph.import'), ['file' => $file, 'format' => 'mapon', 'driver_id' => 'test_driver_09'])
            ->assertRedirect(route('tachograph.drivers.show', Driver::where('external_id', 'test_driver_09')->sole()))
            ->assertSessionHas('status');

        $this->post(route('tachograph.import'), ['file' => UploadedFile::fake()->createWithContent('x.json', '{bad'), 'format' => 'local'])
            ->assertSessionHasErrors('file');
        $this->post(route('tachograph.import'), ['file' => $file, 'format' => 'mapon'])->assertSessionHasErrors('driver_id');
    }

    public function test_driver_page_lists_reports_by_period(): void
    {
        $this->be(User::factory()->create());
        $url = route('tachograph.runs.store', $this->driver());

        $this->post($url, ['start' => '2026-09-28', 'end' => '2026-10-04']); // full fixed week
        $this->post($url, ['start' => '2026-09-28', 'end' => '2026-10-04']); // re-run of the same week
        $this->post($url, ['start' => '2026-09-28', 'end' => '2026-09-29']); // custom period

        $runs = ProcessingRun::where('type', RunType::EVALUATE)->orderBy('id')->get();
        $this->assertCount(3, $runs);

        $response = $this->get(route('tachograph.drivers.show', $this->driver()))->assertOk();

        $response->assertViewHas('reports', function ($reports) use ($runs) {
            return $reports->count() === 2
                && $reports[0]['label'] === 'Week 40, 2026'
                && $reports[0]['latest']->id === $runs[1]->id
                && $reports[0]['older']->pluck('id')->all() === [$runs[0]->id]
                && $reports[1]['label'] === '2 days';
        });

        $response->assertSee('Week 40, 2026')
            ->assertSee('28 Sep – 4 Oct 2026')
            ->assertSee('1 earlier version')
            ->assertSee(route('tachograph.reports.show', $runs[1]))
            ->assertSee(route('tachograph.reports.export', [$runs[1], 'csv', 'findings']))
            ->assertSee('Run history');
    }

    public function test_driver_list_sorts_by_last_check_and_violations(): void
    {
        $this->be(User::factory()->create());
        $make = function (string $id, int $violations, string $checked) {
            $driver = Driver::create(['external_id' => $id, 'origin' => 'mapon', 'display_name' => "Driver {$id}", 'last_active_at' => now()]);
            $run = ProcessingRun::create(['type' => RunType::EVALUATE, 'status' => RunStatus::DONE, 'driver_id' => $driver->id]);
            $run->forceFill(['created_at' => $checked])->save();
            for ($i = 0; $i < $violations; $i++) {
                ComplianceFinding::create([
                    'processing_run_id' => $run->id, 'driver_id' => $driver->id, 'rule' => 'DAILY_REST', 'status' => 'VIOLATION',
                    'certainty' => 'CONFIRMED', 'severity' => 'HIGH', 'period_start' => '2026-09-28', 'period_end' => '2026-09-29',
                    'unit' => 'hours', 'message' => 'x',
                ]);
            }
        };
        $make('A1', 2, '2026-10-01 10:00:00');
        $make('B2', 5, '2026-10-03 10:00:00');
        $make('C3', 0, '2026-10-05 10:00:00');

        $order = fn (string $sort) => $this->get(route('tachograph.drivers.index', ['sort' => $sort]))
            ->assertOk()->viewData('drivers')->pluck('external_id')->filter(fn ($id) => in_array($id, ['A1', 'B2', 'C3']))->values()->all();

        $this->assertSame(['B2', 'A1', 'C3'], $order('violations__desc'));
        $this->assertSame(['C3', 'A1', 'B2'], $order('violations__asc'));
        $this->assertSame(['C3', 'B2', 'A1'], $order('last_check__desc'));

        // Sort links keep the search; the active column shows its direction.
        $this->get(route('tachograph.drivers.index', ['q' => 'Driver', 'sort' => 'violations__desc']))
            ->assertSee('q=Driver&amp;sort=violations__asc', false)
            ->assertSee('↓');
    }

    public function test_public_sign_up_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret-password-1', 'password_confirmation' => 'secret-password-1'])->assertNotFound();
        $this->get(route('login'))->assertOk()->assertDontSee('Sign up');
        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_driver_list_hides_inactive_drivers_behind_a_toggle(): void
    {
        $this->be(User::factory()->create());
        Driver::create(['external_id' => '101', 'origin' => 'mapon', 'display_name' => 'Recent Driver', 'last_active_at' => now()->subDays(3)]);
        Driver::create(['external_id' => '102', 'origin' => 'mapon', 'display_name' => 'Old Driver', 'last_active_at' => now()->subWeeks(6)]);
        Driver::create(['external_id' => '103', 'origin' => 'mapon', 'display_name' => 'Never Driver']);
        Driver::create(['external_id' => '104', 'origin' => 'mapon', 'display_name' => 'Gone Driver', 'is_active' => false, 'last_active_at' => now()]);

        $this->get(route('tachograph.drivers.index'))->assertOk()
            ->assertSee('Recent Driver')
            ->assertDontSee('Old Driver')->assertDontSee('Never Driver')->assertDontSee('Gone Driver')
            ->assertSee('Show inactive (3)');

        $this->get(route('tachograph.drivers.index', ['inactive' => 1]))->assertOk()
            ->assertSee('Recent Driver')->assertSee('Old Driver')->assertSee('Never Driver')
            ->assertSee('Removed in Mapon')->assertSee('Hide inactive');

        // Search covers everyone.
        $this->get(route('tachograph.drivers.index', ['q' => 'Old']))->assertOk()->assertSee('Old Driver');
    }
}
