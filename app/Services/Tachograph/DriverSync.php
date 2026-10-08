<?php

namespace App\Services\Tachograph;

use App\Models\Driver;
use App\Models\Vehicle;
use App\Services\Mapon\MaponClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors Mapon drivers and vehicles locally. Keeps only what the app needs:
 * driver ID, display name and a hash of the card number; vehicle ID and label.
 * Raw responses are not stored (they contain e-mails, phone numbers and GPS positions).
 */
class DriverSync
{
    public const LAST_SYNC_KEY = 'tachograph.last_driver_sync';

    public function __construct(private readonly MaponClient $mapon) {}

    /** @return array{created: int, updated: int, deactivated: int, vehicles: int} */
    public function sync(): array
    {
        $drivers = $this->mapon->getDrivers();
        $units = $this->mapon->getUnits(include: []);

        $stats = ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'vehicles' => 0];

        DB::transaction(function () use ($drivers, $units, &$stats) {
            $seen = [];

            foreach ($drivers as $row) {
                if (empty($row['id'])) {
                    continue;
                }

                $externalId = (string) $row['id'];
                $seen[] = $externalId;
                $name = trim(($row['name'] ?? '').' '.($row['surname'] ?? '')) ?: null;

                $driver = Driver::firstOrNew(['external_id' => $externalId]);
                $driver->fill([
                    'origin' => 'mapon',
                    'display_name' => $name,
                    'card_number_hash' => Driver::hashCardNumber($row['tacho'] ?? null),
                    'is_active' => true,
                ]);

                $stats[$driver->exists ? 'updated' : 'created']++;
                $driver->save();
            }

            $stats['deactivated'] = Driver::where('origin', 'mapon')
                ->where('is_active', true)
                ->whereNotIn('external_id', $seen)
                ->update(['is_active' => false]);

            foreach ($units as $unit) {
                $id = $unit['unit_id'] ?? $unit['id'] ?? null;

                if ($id === null) {
                    continue;
                }

                Vehicle::updateOrCreate(
                    ['external_id' => (string) $id],
                    ['label' => $unit['label'] ?? $unit['number'] ?? null],
                );
                $stats['vehicles']++;
            }
        });

        Cache::forever(self::LAST_SYNC_KEY, now()->toIso8601String());
        Log::channel('tachograph')->info('Driver sync finished', $stats);

        return $stats;
    }
}
