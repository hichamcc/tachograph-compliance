<?php

namespace App\Console\Commands;

use App\Models\RawPayload;
use App\Services\Mapon\MaponClient;
use App\Services\Mapon\MaponException;
use Illuminate\Console\Command;

/**
 * Phase 0 API discovery: confirms authentication, saves raw responses and summarises
 * the status/source mix of driver activities. Prints no names, card numbers or locations.
 */
class TachoDiscover extends Command
{
    protected $signature = 'tacho:discover
        {--driver= : Mapon driver ID (defaults to the first driver returned)}
        {--days=30 : Number of days of activities to fetch (max 31)}
        {--include=* : include[] values for daily_activities}';

    protected $description = 'Probe the Mapon API and summarise the available tachograph data';

    public function handle(MaponClient $mapon): int
    {
        $this->line('Auth mode: <info>'.config('services.mapon.auth_mode').'</info>');

        // 1. Company (confirms authentication + timezone used for "day" buckets)
        try {
            $company = $mapon->getCompany();
            $this->store('company/get', $mapon);
            $this->info('✓ Authentication OK');
            $this->line('  Company timezone: '.($company['companies'][0]['timezone'] ?? 'not returned'));
        } catch (MaponException $e) {
            $this->error('✗ company/get: '.$e->getMessage());

            if (in_array($e->getCode(), [MaponException::KEY_NOT_FOUND, 1001, 1002, 1003, 1004], true)) {
                $this->warn('  Authentication failed. If MAPON_AUTH_MODE=header, check MAPON_AUTH_HEADER or try MAPON_AUTH_MODE=query.');
            }

            return self::FAILURE;
        }

        // 2. Drivers
        $drivers = $this->attempt('driver/list', fn () => $mapon->getDrivers(), $mapon) ?? [];
        $this->line('  Drivers returned: '.count($drivers));

        // 3. Units
        $units = $this->attempt('unit/list', fn () => $mapon->getUnits(), $mapon) ?? [];
        $this->line('  Units returned: '.count($units));

        // 4. Daily activities
        $driverId = (int) ($this->option('driver') ?: ($drivers[0]['id'] ?? 0));
        $days = min(31, max(1, (int) $this->option('days')));

        if ($driverId > 0) {
            $till = now('UTC')->startOfDay();
            $from = $till->subDays($days);

            $activities = $this->attempt('driver/daily_activities', fn () => $mapon->getDriverDailyActivities($driverId, $from, $till, $this->option('include')), $mapon);

            if ($activities !== null) {
                $this->summariseActivities($activities);
            }
        } else {
            $this->warn('  No driver available to fetch activities.');
        }

        // 5. Cross-check endpoint (needs Tachograph remote download add-on)
        $unitId = (int) ($units[0]['unit_id'] ?? $units[0]['id'] ?? 0);

        if ($unitId > 0) {
            $this->attempt('unit_data/driving_time_extended', fn () => $mapon->getDrivingTimeExtended($unitId), $mapon);
        }

        $this->newLine();
        $this->line('Raw responses saved under storage/app/private/'.config('tachograph.raw_payload_path'));

        return self::SUCCESS;
    }

    private function attempt(string $endpoint, callable $call, MaponClient $mapon): ?array
    {
        try {
            $result = $call();
            $this->store($endpoint, $mapon);
            $this->info("✓ {$endpoint}");

            return $result;
        } catch (MaponException $e) {
            $this->error("✗ {$endpoint}: ".$e->getMessage());

            if ($e->getCode() === MaponException::NEEDS_PAID_ADDON) {
                $this->warn('  Needs a paid add-on (Tachograph remote download). Cross-check will be disabled.');
            }

            return null;
        }
    }

    private function store(string $endpoint, MaponClient $mapon): void
    {
        if ($body = $mapon->lastBody()) {
            RawPayload::store($endpoint, $body);
        }
    }

    private function summariseActivities(array $days): void
    {
        $statuses = [];
        $sources = [];
        $zeroDuration = 0;
        $total = 0;

        foreach ($days as $day) {
            foreach ($day['activities'] ?? [] as $activity) {
                $total++;
                $status = $activity['status'] ?? '?';
                $source = $activity['source'] ?? '?';
                $statuses[$status] = ($statuses[$status] ?? 0) + 1;
                $sources[$source] = ($sources[$source] ?? 0) + 1;

                if (($activity['duration'] ?? null) === 0 && ($activity['end'] ?? 0) > ($activity['start'] ?? 0)) {
                    $zeroDuration++;
                }
            }
        }

        $this->line('  Days: '.count($days).", activities: {$total}, duration=0 with real span: {$zeroDuration}");

        arsort($statuses);
        arsort($sources);

        $this->table(['status', 'count'], collect($statuses)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->table(['source', 'count', 'share'], collect($sources)->map(fn ($n, $k) => [$k, $n, $total ? round($n / $total * 100, 1).'%' : '-'])->values()->all());
    }
}
