<?php

namespace Tests\Feature\Tachograph;

use App\Models\ComplianceFinding;
use App\Models\Driver;
use App\Models\ProcessingRun;
use App\Models\User;
use App\RunStatus;
use App\RunType;
use App\Services\Tachograph\DriverSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function evaluation(Driver $driver, array $findings, string $createdAt = 'now', RunStatus $status = RunStatus::DONE): ProcessingRun
    {
        $run = ProcessingRun::create([
            'type' => RunType::EVALUATE, 'status' => $status, 'driver_id' => $driver->id,
            'period_start' => '2026-09-28 00:00:00', 'period_end' => '2026-10-05 00:00:00',
        ]);
        $run->forceFill(['created_at' => now()->modify($createdAt)])->save();

        foreach ($findings as [$rule, $status, $certainty, $end]) {
            ComplianceFinding::create([
                'processing_run_id' => $run->id, 'driver_id' => $driver->id, 'rule' => $rule, 'status' => $status,
                'certainty' => $certainty, 'severity' => 'HIGH', 'period_start' => '2026-09-28 00:00:00',
                'period_end' => $end ?? '2026-09-29 00:00:00', 'measured_value' => 21, 'unit' => 'hours', 'message' => 'x',
            ]);
        }

        return $run;
    }

    public function test_dashboard_shows_latest_evaluation_per_driver(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(12, 0));

        $a = Driver::create(['external_id' => '1', 'origin' => 'mapon', 'display_name' => 'Alpha Driver']);
        $b = Driver::create(['external_id' => '2', 'origin' => 'mapon', 'display_name' => 'Bravo Driver']);
        $c = Driver::create(['external_id' => '3', 'origin' => 'mapon', 'display_name' => 'Charlie Driver']);
        Driver::create(['external_id' => '4', 'origin' => 'mapon']);

        // Older run of A had a violation; the latest one does not count it twice.
        $this->evaluation($a, [['DAILY_REST', 'VIOLATION', 'CONFIRMED', null]], '-2 days');
        $latestA = $this->evaluation($a, [
            ['DAILY_REST', 'VIOLATION', 'CONFIRMED', null],
            ['BREAK_AFTER_4_5_HOURS', 'VIOLATION', 'CONFIRMED', null],
            ['WEEKLY_REST_COMPENSATION', 'WARNING', 'CONFIRMED', '2026-10-12 00:00:00'],
        ]);
        $this->evaluation($b, [['WEEKLY_DRIVING_LIMIT', 'VIOLATION', 'POTENTIAL', null]]);
        $this->evaluation($c, [['DAILY_DRIVING_LIMIT', 'INCOMPLETE_DATA', 'POTENTIAL', null]]);
        $this->evaluation($c, [['DAILY_REST', 'VIOLATION', 'CONFIRMED', null]], '-10 days'); // too old

        ProcessingRun::create(['type' => RunType::FETCH, 'status' => RunStatus::FAILED, 'driver_id' => $a->id]);
        Cache::forever(DriverSync::LAST_SYNC_KEY, now()->toIso8601String());

        $this->be(User::factory()->create());

        $response = $this->get(route('app'))->assertOk();
        $response->assertViewHas('headline', ['checked' => 3, 'active' => 4, 'confirmed' => 1, 'potential' => 1, 'incomplete' => 1]);
        $response->assertViewHas('attention', fn ($runs) => $runs->pluck('driver.display_name')->all() === ['Alpha Driver', 'Bravo Driver']
            && $runs->first()->id === $latestA->id && $runs->first()->confirmed === 2);
        $response->assertViewHas('compensation', fn ($rows) => $rows->count() === 1 && $rows->first()->driver_id === $a->id);
        $response->assertViewHas('system', fn ($s) => $s['failed_24h'] === 1 && $s['last_sync'] !== null && $s['last_fetch'] !== null);

        $response->assertSee('Alpha Driver')
            ->assertSee('Break after 4.5 hours, Daily rest')
            ->assertSee('in 5 days')
            ->assertSee(route('tachograph.reports.show', $latestA));
    }

    public function test_empty_dashboard(): void
    {
        $this->be(User::factory()->create());

        $this->get(route('app'))->assertOk()->assertSee('No checks in the last 7 days.')->assertDontSee('Weekly rest compensation due');
    }
}
