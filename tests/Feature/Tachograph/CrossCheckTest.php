<?php

namespace Tests\Feature\Tachograph;

use App\Models\ActivityRecord;
use App\Models\Driver;
use App\Models\ValidationIssue;
use App\Models\Vehicle;
use App\RunType;
use App\Services\Tachograph\CrossCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrossCheckTest extends TestCase
{
    use RefreshDatabase;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(new \DateTimeImmutable('2026-10-07 12:00:00', new \DateTimeZone('UTC'))); // Wednesday
        config(['services.mapon.key' => 'k', 'services.mapon.base_url' => 'https://mapon.test/api/v1/']);

        $this->driver = Driver::create(['external_id' => '424242', 'origin' => 'mapon']);
        $vehicle = Vehicle::create(['external_id' => '900001']);

        // Monday: 4h + 4h driving (8h), then rest.
        foreach ([['DRIVING', '2026-10-05 06:00', '2026-10-05 10:00'], ['REST', '2026-10-05 10:00', '2026-10-05 10:45'],
            ['DRIVING', '2026-10-05 10:45', '2026-10-05 14:45'], ['REST', '2026-10-05 14:45', '2026-10-07 12:00']] as $i => [$type, $start, $end]) {
            ActivityRecord::create([
                'driver_id' => $this->driver->id, 'vehicle_id' => $vehicle->id, 'type' => $type, 'raw_status' => $type, 'source' => 'ddd',
                'start_at' => $start, 'end_at' => $end, 'duration_seconds' => strtotime($end) - strtotime($start),
                'source_event_key' => sha1((string) $i),
            ]);
        }
    }

    private function fakeCounters(int $weekDrivingSeconds): void
    {
        Http::fake(['*driving_time_extended.json*' => Http::response(['data' => [
            'driver1' => [
                'driver_id' => 424242, 'driver_name' => 'IGNORED', 'driver_card_id' => 'IGNORED',
                'week' => ['driving' => $weekDrivingSeconds, 'previous_week_driving' => 0, '10h_driving_extensions_used' => 0, '9h_rest_shortening_used' => 0],
            ],
            'driver2' => ['driver_id' => 1, 'week' => ['driving' => 999999]],
        ]])]);
    }

    public function test_matching_counters(): void
    {
        $this->fakeCounters(8 * 3600 + 600); // within the 30-minute tolerance

        $result = app(CrossCheck::class)->check($this->driver);

        $this->assertTrue($result['unit_found']);
        $this->assertSame(8.0, $result['rows'][0]['ours']);
        $this->assertSame([true, true, true], array_slice(array_column($result['rows'], 'match'), 0, 3));
        $this->assertSame(RunType::CROSSCHECK, $result['run']->type);
        $this->assertSame(0, ValidationIssue::where('type', 'CROSSCHECK_MISMATCH')->count());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'unit_id=900001'));
    }

    public function test_mismatch_is_recorded_without_personal_data(): void
    {
        $this->fakeCounters(10 * 3600);

        $this->artisan('tacho:crosscheck', ['--driver' => ['424242']])
            ->expectsOutputToContain('mismatch')
            ->doesntExpectOutputToContain('IGNORED')
            ->assertSuccessful();

        $issue = ValidationIssue::where('type', 'CROSSCHECK_MISMATCH')->sole();
        $this->assertSame('INFO', $issue->severity);
        $this->assertSame('week_driving', $issue->context['metric']);
        $this->assertStringNotContainsString('IGNORED', json_encode($issue->toArray()));
    }

    public function test_driver_not_on_last_vehicle(): void
    {
        Http::fake(['*' => Http::response(['data' => ['driver1' => ['driver_id' => 1, 'week' => []]]])]);

        $result = app(CrossCheck::class)->check($this->driver);

        $this->assertFalse($result['unit_found']);
        $this->assertSame([null, null, null, null], array_column($result['rows'], 'match'));
    }
}
