<?php

namespace App\Console\Commands;

use App\Models\Driver;
use App\Services\Tachograph\RefreshService;
use Illuminate\Console\Command;

/**
 * Refreshes all due drivers in one go (no time limit on the command line). For hosts with
 * command cron:  0 *\/2 * * *  php /path/to/app/artisan tacho:refresh
 * Hosts with only "call URL" cron use the cron URL instead (see docs/DEPLOYMENT.md).
 */
class TachoRefresh extends Command
{
    protected $signature = 'tacho:refresh
        {--driver=* : Only these Mapon driver IDs}
        {--no-sync : Do not sync the driver list first}
        {--all : Refresh every driver, not only those due}';

    protected $description = 'Download the latest Mapon data for due drivers and re-check the current week (for cron)';

    public function handle(RefreshService $refresh): int
    {
        $stats = $refresh->run(
            only: array_values(array_filter((array) $this->option('driver'))),
            all: (bool) $this->option('all'),
            sync: $this->option('no-sync') ? false : true,
            onDriver: function (Driver $driver, ?string $error) {
                $error
                    ? $this->line("  <fg=red>✗</> {$driver->external_id}: {$error}")
                    : $this->line("  ✓ {$driver->external_id}", verbosity: 'v');
            },
        );

        if ($stats === null) {
            $this->warn('A refresh is already running; skipped.');

            return self::SUCCESS;
        }

        $this->table(['Drivers refreshed', 'Week checks', 'Failed', 'Time'], [[
            $stats['refreshed'], $stats['checked'], $stats['failed'], gmdate('H:i:s', $stats['seconds']),
        ]]);

        return $stats['failed'] > 0 && $stats['refreshed'] === 0 ? self::FAILURE : self::SUCCESS;
    }
}
