<?php

namespace App\Console\Commands;

use App\Services\Mapon\MaponException;
use App\Services\Tachograph\DriverSync;
use Illuminate\Console\Command;

class TachoSyncDrivers extends Command
{
    protected $signature = 'tacho:sync-drivers';

    protected $description = 'Sync drivers and vehicles from Mapon';

    public function handle(DriverSync $sync): int
    {
        try {
            $stats = $sync->sync();
        } catch (MaponException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Created', 'Updated', 'Deactivated', 'Vehicles'], [array_values($stats)]);

        return self::SUCCESS;
    }
}
