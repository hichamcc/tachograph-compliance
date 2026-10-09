<?php

namespace Tests\Feature\Tachograph;

use App\Models\ActivityRecord;
use App\Models\Driver;
use App\Models\ProcessingRun;
use App\Models\ValidationIssue;
use App\Models\Vehicle;
use App\RunStatus;
use App\Services\Tachograph\ActivityStore;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Data\Period;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportFileCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_imports_local_fixture_and_is_idempotent(): void
    {
        $this->artisan('tacho:import-file', ['path' => 'tests/Fixtures/activities/week_happy_path.json'])->assertSuccessful();
        $this->artisan('tacho:import-file', ['path' => 'tests/Fixtures/activities/week_happy_path.json'])->assertSuccessful();

        $driver = Driver::where('external_id', 'test_driver_01')->sole();
        $this->assertSame('local', $driver->origin);
        $this->assertSame(13, ActivityRecord::where('driver_id', $driver->id)->count());

        $run = ProcessingRun::latest('created_at')->first();
        $this->assertSame(RunStatus::DONE, $run->status);
        $this->assertSame(13, $run->records_processed);
        $this->assertSame($driver->id, $run->driver_id);
        $this->assertSame('2026-09-26 18:00:00', $run->period_start->format('Y-m-d H:i:s'));
        $this->assertCount(1, $run->rawPayloads);
        Storage::disk('local')->assertExists($run->rawPayloads->first()->payload_path);
    }

    public function test_round_trip_through_store_preserves_utc_times(): void
    {
        $this->artisan('tacho:import-file', ['path' => 'tests/Fixtures/activities/week_happy_path.json'])->assertSuccessful();

        $activities = app(ActivityStore::class)->load(
            Driver::where('external_id', 'test_driver_01')->sole(),
            Period::fromStrings('2026-09-28 00:00', '2026-09-29 00:00'),
        );

        $this->assertCount(7, $activities); // incl. both rests overlapping the day edges
        $this->assertSame('2026-09-28T06:00:00+00:00', $activities[1]->start->format(DATE_ATOM));
        $this->assertSame(ActivityType::WORK, $activities[1]->type);
        $this->assertSame('test_vehicle_01', $activities[1]->vehicleId);
    }

    public function test_data_quality_issues_are_recorded(): void
    {
        $this->artisan('tacho:import-file', ['path' => 'tests/Fixtures/activities/data_quality_issues.json'])->assertSuccessful();

        $run = ProcessingRun::sole();
        $this->assertSame(11, $run->records_retrieved);
        $this->assertSame(5, $run->records_invalid);

        $types = ValidationIssue::pluck('type')->unique()->sort()->values()->all();
        $this->assertContains('OVERLAPPING_ACTIVITY', $types);
        $this->assertContains('INCONSISTENT_ASSOCIATION', $types);
        $this->assertContains('MISSING_DRIVER_ID', $types);
        $this->assertNull(ValidationIssue::where('type', 'MISSING_DRIVER_ID')->value('driver_id'));
    }

    public function test_imports_raw_mapon_file(): void
    {
        $this->artisan('tacho:import-file', [
            'path' => 'tests/Fixtures/mapon/daily_activities_sample.json',
            '--format' => 'mapon',
            '--driver' => 'test_driver_01',
        ])->assertSuccessful();

        $this->assertSame(220, ActivityRecord::count());
        $this->assertSame(1, ActivityRecord::where('type', 'UNKNOWN')->count());
        $this->assertSame(2, Vehicle::count());
    }

    public function test_mapon_format_requires_driver(): void
    {
        $this->artisan('tacho:import-file', ['path' => 'tests/Fixtures/mapon/daily_activities_sample.json', '--format' => 'mapon'])
            ->expectsOutputToContain('driver ID is required')
            ->assertFailed();
    }

    public function test_missing_file_and_invalid_json_fail_cleanly(): void
    {
        $this->artisan('tacho:import-file', ['path' => 'nope.json'])->assertFailed();

        $tmp = tempnam(sys_get_temp_dir(), 'tacho');
        file_put_contents($tmp, '{not json');
        $this->artisan('tacho:import-file', ['path' => $tmp])->expectsOutputToContain('Invalid JSON')->assertFailed();
        unlink($tmp);

        $this->assertSame(0, ProcessingRun::count());
    }
}
