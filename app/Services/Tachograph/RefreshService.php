<?php

namespace App\Services\Tachograph;

use App\Models\Driver;
use App\Models\ProcessingRun;
use App\Models\RawPayload;
use App\RunType;
use App\Services\Mapon\MaponException;
use App\Tachograph\Data\Period;
use App\Tachograph\Periods\WeekCalendar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps drivers' data current and re-checks the current week. Works on "due" drivers:
 *   - never downloaded,
 *   - active (driving/work in the last weeks) and not refreshed for ~refresh_interval,
 *   - inactive and not refreshed for refresh_inactive_hours.
 * With a time budget it stops after that many seconds and the next call continues
 * (used by the cron URL on hosts without command-line cron).
 */
class RefreshService
{
    /** On Monday–Wednesday the previous week is re-checked too (late driver-card downloads). */
    private const PREVIOUS_WEEK_DAYS = 3;

    /** Automatic download runs are kept this long (their data stays). */
    private const KEEP_FETCH_RUNS_DAYS = 14;

    /** A driver whose download failed is retried after this many minutes. */
    private const FAILURE_BACKOFF_MINUTES = 60;

    public function __construct(
        private readonly DriverSync $sync,
        private readonly FetchService $fetch,
        private readonly EvaluationService $evaluation,
    ) {}

    /**
     * @param  float|null  $budgetSeconds  stop starting new drivers after this long (null: no limit)
     * @param  list<string>  $only  only these Mapon driver IDs (always refreshed)
     * @param  bool  $all  refresh every Mapon driver, due or not
     * @param  bool|null  $sync  sync the driver list first (null: when due)
     * @return array{refreshed: int, checked: int, failed: int, remaining: int, seconds: int, synced: bool}|null null when another refresh is running
     */
    public function run(?float $budgetSeconds = null, array $only = [], bool $all = false, ?bool $sync = null, ?callable $onDriver = null): ?array
    {
        $lock = Cache::lock('tachograph.refresh', 6 * 3600);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->refresh($budgetSeconds, $only, $all, $sync, $onDriver);
        } finally {
            $lock->release();
        }
    }

    /** Drivers that need a refresh now, most urgent first. */
    public function dueDrivers(): Collection
    {
        $activeSince = Driver::activeSince();
        $activeDue = now()->subMinutes(max(10, $this->intervalMinutes() - 10));
        $inactiveDue = now()->subHours((int) config('tachograph.refresh_inactive_hours', 24));

        return Driver::query()
            ->where('origin', 'mapon')
            ->active()
            ->where(fn ($q) => $q
                ->whereNull('last_fetched_at')
                ->orWhere(fn ($q) => $q->where('last_active_at', '>=', $activeSince)->where('last_fetched_at', '<', $activeDue))
                ->orWhere(fn ($q) => $q->where(fn ($q) => $q->whereNull('last_active_at')->orWhere('last_active_at', '<', $activeSince))
                    ->where('last_fetched_at', '<', $inactiveDue)))
            ->orderByRaw('case when last_fetched_at is null then 0 else 1 end')
            ->orderBy('last_fetched_at')
            ->orderBy('id')
            ->get()
            ->reject(fn (Driver $d) => Cache::has($this->failureKey($d)))
            ->values();
    }

    private function refresh(?float $budgetSeconds, array $only, bool $all, ?bool $sync, ?callable $onDriver): array
    {
        $started = microtime(true);
        $synced = false;

        if ($only === [] && ($sync ?? $this->syncDue())) {
            try {
                $this->sync->sync();
                $synced = true;
            } catch (MaponException $e) {
                Log::channel('tachograph')->warning('Driver sync failed during refresh', ['code' => $e->getCode()]);
            }
        }

        $drivers = match (true) {
            $only !== [] => Driver::where('origin', 'mapon')->whereIn('external_id', $only)->get(),
            $all => Driver::where('origin', 'mapon')->active()->orderBy('id')->get(),
            default => $this->dueDrivers(),
        };

        $periods = $this->periods();
        $span = new Period($periods[0]->start, end($periods)->end);
        $stats = ['refreshed' => 0, 'checked' => 0, 'failed' => 0];
        $done = 0;

        foreach ($drivers as $driver) {
            if ($budgetSeconds !== null && microtime(true) - $started >= $budgetSeconds) {
                break;
            }

            $done++;

            try {
                $this->fetch->fetchNow($driver, $span);
                $stats['refreshed']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                Cache::put($this->failureKey($driver), true, now()->addMinutes(self::FAILURE_BACKOFF_MINUTES));
                Log::channel('tachograph')->warning('Refresh failed', ['driver' => $driver->logId(), 'exception' => class_basename($e), 'code' => $e->getCode()]);
                $onDriver && $onDriver($driver, $e instanceof MaponException ? $e->getMessage() : class_basename($e));

                continue;
            }

            $driver->refresh();

            if ($driver->isRecentlyActive()) {
                foreach ($periods as $period) {
                    try {
                        $this->evaluation->evaluate($driver, $period);
                        $stats['checked']++;
                    } catch (Throwable $e) {
                        $stats['failed']++;
                        report($e);
                    }
                }
            }

            $onDriver && $onDriver($driver, null);
        }

        $this->housekeeping();

        $result = $stats + [
            'remaining' => max(0, $drivers->count() - $done),
            'seconds' => (int) round(microtime(true) - $started),
            'synced' => $synced,
        ];

        Cache::forever('tachograph.last_refresh', now()->toIso8601String());
        Log::channel('tachograph')->info('Refresh finished', $result);

        return $result;
    }

    private function syncDue(): bool
    {
        $last = Cache::get(DriverSync::LAST_SYNC_KEY);

        return $last === null || now()->diffInMinutes($last, absolute: true) >= max(10, $this->intervalMinutes() - 10);
    }

    private function housekeeping(): void
    {
        if (! Cache::add('tachograph.refresh_housekeeping', true, now()->addHours(6))) {
            return;
        }

        ProcessingRun::where('type', RunType::FETCH->value)
            ->whereNull('user_id')
            ->where('created_at', '<', now()->subDays(self::KEEP_FETCH_RUNS_DAYS))
            ->delete();
        RawPayload::pruneDaily();
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

    private function intervalMinutes(): int
    {
        return (int) config('tachograph.refresh_interval_minutes', 120);
    }

    private function failureKey(Driver $driver): string
    {
        return "tachograph.refresh_failed.{$driver->id}";
    }
}
