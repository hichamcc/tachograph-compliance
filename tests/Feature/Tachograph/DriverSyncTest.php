<?php

namespace Tests\Feature\Tachograph;

use App\Models\Driver;
use App\Models\RawPayload;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DriverSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.mapon.key' => 'test-key', 'services.mapon.base_url' => 'https://mapon.test/api/v1/']);
    }

    private function fakeMapon(array $drivers): void
    {
        Http::fake([
            'mapon.test/api/v1/driver/list.json*' => Http::response(['data' => ['drivers' => $drivers]]),
            'mapon.test/api/v1/unit/list.json*' => Http::response(['data' => ['units' => [
                ['unit_id' => 900001, 'label' => 'Truck 1', 'number' => 'AB12345', 'lat' => 55.6, 'lng' => 12.5],
            ]]]),
        ]);
    }

    public function test_sync_creates_drivers_and_vehicles_with_minimal_data(): void
    {
        $this->fakeMapon([
            ['id' => 101, 'name' => 'Test', 'surname' => 'Driver', 'email' => 'x@example.com', 'phone' => '+45 1234', 'tacho' => 'DK12345678901234'],
            ['id' => 102, 'name' => '', 'surname' => '', 'tacho' => ''],
        ]);

        $this->artisan('tacho:sync-drivers')->assertSuccessful();

        $driver = Driver::where('external_id', '101')->sole();
        $this->assertSame('Test Driver', $driver->display_name);
        $this->assertSame('mapon', $driver->origin);
        $this->assertSame(Driver::hashCardNumber('DK12345678901234'), $driver->card_number_hash);
        $this->assertNotSame('DK12345678901234', $driver->card_number_hash);
        $this->assertNull(Driver::where('external_id', '102')->sole()->display_name);

        $this->assertSame('Truck 1', Vehicle::where('external_id', '900001')->sole()->label);

        // Raw driver/unit lists (e-mails, phones, GPS) are never stored.
        $this->assertSame(0, RawPayload::count());
        Http::assertSent(fn ($r) => ! str_contains(urldecode($r->url()), 'include[]'));
    }

    public function test_drivers_missing_from_mapon_are_deactivated(): void
    {
        Driver::create(['external_id' => '999', 'origin' => 'mapon']);
        Driver::create(['external_id' => 'test_driver_01', 'origin' => 'local']);
        $this->fakeMapon([['id' => 101, 'name' => 'A', 'surname' => 'B']]);

        $this->artisan('tacho:sync-drivers')->assertSuccessful();

        $this->assertFalse(Driver::where('external_id', '999')->sole()->is_active);
        $this->assertTrue(Driver::where('external_id', 'test_driver_01')->sole()->is_active);
    }

    public function test_sync_failure_is_reported_without_the_key(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 1005, 'msg' => 'Key not found']])]);

        $this->artisan('tacho:sync-drivers')
            ->expectsOutputToContain('Mapon error 1005')
            ->doesntExpectOutputToContain('test-key')
            ->assertFailed();
    }
}
