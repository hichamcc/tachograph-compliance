<?php

namespace App\Console\Commands;

use App\Models\Driver;
use App\Models\ProcessingRun;
use App\Models\RawPayload;
use App\RunType;
use App\Services\Mapon\MaponException;
use App\Services\Tachograph\DriverSync;
use App\Services\Tachograph\EvaluationService;
use App\Services\Tachograph\FetchService;
use App\Tachograph\Data\Period;
use App\Tachograph\Periods\WeekCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps every driver's data current (at least 4 weeks stored) and re-checks the current week.
 * Meant for cron every 2 hours; runs everything in this process, without the queue.
 *
 *   0 *\/2 * * *  cd /path/to/app && php artisan tacho:refresh
 */
class TachoRefresh extends Command
{
    protected $signature = 'tacho:refresh
        {--driver=* : Only these Mapon driver IDs}
        {--no-sync : Do not sync the driver list first}
        {--all : Also refresh inactive drivers that were refreshed recently}';

    protected $description = 'Download the latest Mapon data for all drivers and re-check the current week (for cron)';

    /** On Monday–Wednesday the previous week is re-checked too (late driver-card downloads). */
    private const PREVIOUS_WEEK_DAYS = 3;

    /** Automatic download runs are kept this long (their data stays). */
    private const KEEP_FETCH_RUNS_DAYS = 14;

    public function handle(DriverSync $sync, FetchService $fetch, EvaluationService $evaluation): int
    {
        $lock = Cache::lock('tachograph.refresh', 6 * 3600);

        if (! $lock->get()) {
            $this->warn('A refresh is already running; skipped.');

            return self::SUCCESS;
        }

        try {
            return $this->refresh($sync, $fetch, $evaluation);
        } finally {
            $lock->release();
        }
    }

    private function refresh(DriverSync $sync, FetchService $fetch, EvaluationService $evaluation): int
    {
        $started = microtime(true);
        $only = array_filter((array) $this->option('driver'));

        if (! $this->option('no-sync') && $only === []) {
            try {
                $sync->sync();
            } catch (MaponException $e) {
                $this->error('Driver sync failed: '.$e->getMessage().' Continuing with the stored list.');
            }
        }

        $drivers = Driver::query()
            ->where('origin', 'mapon')
            ->active()
            ->when($only, fn ($q) => $q->whereIn('external_id', $only))
            ->orderByDesc('last_active_at')
            ->orderBy('id')
            ->get();

        $periods = $this->periods();
        $span = new Period($periods[0]->start, end($periods)->end);
        $staleBefore = now()->subHours((int) config('tachograph.refresh_inactive_hours', 24));
        $stats = ['refreshed' => 0, 'checked' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($drivers as $driver) {
            $recentlyRefreshed = $driver->last_fetched_at?->gt($staleBefore);

            if (! $driver->isRecentlyActive() && $recentlyRefreshed && ! $this->option('all') && $only === []) {
                $stats['skipped']++; // inactive: refreshed once a day is enough

                continue;
            }

            try {
                $fetch->fetchNow($driver, $span);
                $stats['refreshed']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->line("  <fg=red>✗</> {$driver->external_id}: ".($e instanceof MaponException ? $e->getMessage() : class_basename($e)));
                Log::channel('tachograph')->warning('Refresh failed', ['driver' => $driver->logId(), 'exception' => class_basename($e)]);

                continue;
            }

            $driver->refresh();

            if (! $driver->isRecentlyActive()) {
                continue;
            }

            foreach ($periods as $period) {
                try {
                    $evaluation->evaluate($driver, $period);
                    $stats['checked']++;
                } catch (Throwable $e) {
                    $stats['failed']++;
                    report($e);
                }
            }

            $this->line("  ✓ {$driver->external_id}", verbosity: 'v');
        }

        ProcessingRun::where('type', RunType::FETCH->value)
            ->whereNull('user_id')
            ->where('created_at', '<', now()->subDays(self::KEEP_FETCH_RUNS_DAYS))
            ->delete();
        RawPayload::pruneDaily();

        $seconds = (int) round(microtime(true) - $started);
        $this->table(['Drivers refreshed', 'Week checks', 'Skipped (inactive)', 'Failed', 'Time'], [[
            $stats['refreshed'], $stats['checked'], $stats['skipped'], $stats['failed'], gmdate('H:i:s', $seconds),
        ]]);
        Log::channel('tachograph')->info('Refresh finished', $stats + ['seconds' => $seconds]);

        return $stats['failed'] > 0 && $stats['refreshed'] === 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return non-empty-list<Period> the current fixed week, preceded by the previous one early in the week */
    private function periods(): array
    {
        $calendar = new WeekCalendar(config('tachograph.week_timezone', 'UTC'));
        $now = now()->toImmutable()->utc();
        $current = $calendar->weekOf($now);
        $daysIntoWeek = ($now->getTimestamp() - $current->start->getTimestamp()) / 86400;

        return $daysIntoWeek < self::PREVIOUS_WEEK_DAYS
            ? [$calendar->previous($current), $current]
            : [$current];
    }
}
