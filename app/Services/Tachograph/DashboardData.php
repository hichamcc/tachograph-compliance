<?php

namespace App\Services\Tachograph;

use App\Models\ComplianceFinding;
use App\Models\Driver;
use App\Models\ProcessingRun;
use App\RunStatus;
use App\RunType;
use App\Tachograph\Rules\WeeklyRestRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Figures for the dashboard, based on each driver's latest finished evaluation
 * from the last N days (normally produced by the nightly run).
 */
class DashboardData
{
    public const DAYS = 7;

    /** @var Collection<int, ProcessingRun>|null */
    private ?Collection $latestRuns = null;

    /** @return Collection<int, ProcessingRun> latest done evaluation per driver, with finding counts */
    public function latestRuns(): Collection
    {
        if ($this->latestRuns !== null) {
            return $this->latestRuns;
        }

        // ULIDs sort by creation time, so max(id) is the latest run per driver.
        $ids = ProcessingRun::query()
            ->where('type', RunType::EVALUATE->value)
            ->where('status', RunStatus::DONE->value)
            ->where('created_at', '>=', now()->subDays(self::DAYS))
            ->whereNotNull('driver_id')
            ->groupBy('driver_id')
            ->selectRaw('max(id) as id')
            ->pluck('id');

        $counts = ComplianceFinding::query()
            ->whereIn('processing_run_id', $ids)
            ->groupBy('processing_run_id')
            ->selectRaw("processing_run_id,
                sum(case when status = 'VIOLATION' and certainty = 'CONFIRMED' then 1 else 0 end) as confirmed,
                sum(case when status = 'VIOLATION' and certainty = 'POTENTIAL' then 1 else 0 end) as potential,
                sum(case when status = 'INCOMPLETE_DATA' then 1 else 0 end) as incomplete")
            ->get()
            ->keyBy('processing_run_id');

        $rules = ComplianceFinding::query()
            ->whereIn('processing_run_id', $ids)
            ->where('status', 'VIOLATION')
            ->distinct()
            ->get(['processing_run_id', 'rule'])
            ->groupBy('processing_run_id')
            ->map(fn ($rows) => $rows->pluck('rule')->sort()->values()->all());

        return $this->latestRuns = ProcessingRun::with('driver')
            ->whereIn('id', $ids)
            ->get()
            ->each(function (ProcessingRun $run) use ($counts, $rules) {
                $c = $counts[$run->id] ?? null;
                $run->setAttribute('confirmed', (int) ($c->confirmed ?? 0));
                $run->setAttribute('potential', (int) ($c->potential ?? 0));
                $run->setAttribute('incomplete', (int) ($c->incomplete ?? 0));
                $run->setAttribute('violation_rules', $rules[$run->id] ?? []);
            });
    }

    /** @return array{checked: int, active: int, confirmed: int, potential: int, incomplete: int} drivers per category */
    public function headline(): array
    {
        $runs = $this->latestRuns();

        return [
            'checked' => $runs->count(),
            'active' => Driver::active()->where('origin', 'mapon')->count(),
            'confirmed' => $runs->where('confirmed', '>', 0)->count(),
            'potential' => $runs->where('confirmed', 0)->where('potential', '>', 0)->count(),
            'incomplete' => $runs->where('confirmed', 0)->where('potential', 0)->where('incomplete', '>', 0)->count(),
        ];
    }

    /** @return Collection<int, ProcessingRun> drivers with violations, worst first */
    public function attention(int $limit = 10): Collection
    {
        return $this->latestRuns()
            ->filter(fn (ProcessingRun $r) => $r->confirmed + $r->potential > 0)
            ->sortBy([['confirmed', 'desc'], ['potential', 'desc']])
            ->take($limit)
            ->values();
    }

    /** @return Collection<int, ComplianceFinding> pending weekly-rest compensation, soonest first */
    public function compensationDue(int $limit = 10): Collection
    {
        return ComplianceFinding::query()
            ->with('driver')
            ->whereIn('processing_run_id', $this->latestRuns()->pluck('id'))
            ->where('rule', WeeklyRestRule::COMPENSATION_CODE)
            ->where('status', 'WARNING')
            ->where('period_end', '>=', now())
            ->orderBy('period_end')
            ->limit($limit)
            ->get();
    }

    /** @return array{last_sync: ?Carbon, last_fetch: ?Carbon, failed_24h: int, queued: ?int} */
    public function system(): array
    {
        $lastSync = Cache::get(DriverSync::LAST_SYNC_KEY);
        $lastFetch = ProcessingRun::where('type', RunType::FETCH->value)->max('created_at');

        try {
            $queued = Queue::size();
        } catch (Throwable) {
            $queued = null;
        }

        return [
            'last_sync' => $lastSync ? Carbon::parse($lastSync) : null,
            'last_fetch' => $lastFetch ? Carbon::parse($lastFetch, 'UTC') : null,
            'failed_24h' => ProcessingRun::where('status', RunStatus::FAILED->value)->where('created_at', '>=', now()->subDay())->count(),
            'queued' => $queued,
        ];
    }
}
