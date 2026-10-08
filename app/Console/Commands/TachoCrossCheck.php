<?php

namespace App\Console\Commands;

use App\Models\Driver;
use App\Services\Mapon\MaponException;
use App\Services\Tachograph\CrossCheck;
use Illuminate\Console\Command;

class TachoCrossCheck extends Command
{
    protected $signature = 'tacho:crosscheck {--driver=* : Mapon driver ID(s); fetch their data first with tacho:fetch}';

    protected $description = "Compare this week's values with Mapon's own driving-time counters (test aid)";

    public function handle(CrossCheck $check): int
    {
        $drivers = Driver::where('origin', 'mapon')->whereIn('external_id', (array) $this->option('driver'))->get();

        if ($drivers->isEmpty()) {
            $this->error('Pass --driver=ID for one or more synced Mapon drivers.');

            return self::INVALID;
        }

        $failed = false;

        foreach ($drivers as $driver) {
            try {
                $result = $check->check($driver);
            } catch (MaponException $e) {
                $this->error("Driver {$driver->external_id}: {$e->getMessage()}");
                $failed = true;

                continue;
            }

            $this->info("Driver {$driver->external_id} (our data until {$result['data_until']})");

            if (! $result['unit_found']) {
                $this->warn('  Mapon has no live counters for this driver on their last vehicle.');
            }

            $this->table(['Metric', 'Mapon', 'Ours', ''], array_map(fn ($r) => [
                $r['metric'],
                $r['mapon'] ?? '–',
                $r['ours'] ?? '–',
                match ($r['match']) {
                    true => '✓', false => '✗ mismatch', null => ''
                },
            ], $result['rows']));
        }

        $this->line('Mapon counters are live (incl. data not yet downloaded from the card); small differences for the current week are expected.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
