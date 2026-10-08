<?php

namespace App\Http\Controllers\Tachograph;

use App\Http\Controllers\Controller;
use App\Models\ActivityRecord;
use App\Models\ComplianceFinding;
use App\Models\Driver;
use App\Models\ProcessingRun;
use App\RunStatus;
use App\RunType;
use App\Services\Mapon\MaponException;
use App\Services\Tachograph\DriverSync;
use App\Tachograph\Periods\WeekCalendar;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DriverController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        [$sort, $direction] = array_pad(explode('__', (string) $request->query('sort', '')), 2, 'asc');
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        $latestRun = fn ($query) => $query->select('id')->from('processing_runs')
            ->whereColumn('processing_runs.driver_id', 'drivers.id')
            ->where('type', RunType::EVALUATE->value)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(1);

        $drivers = Driver::query()
            ->select('drivers.*')
            ->addSelect([
                'last_data_at' => ActivityRecord::select('end_at')->whereColumn('driver_id', 'drivers.id')->orderByDesc('end_at')->limit(1),
                'latest_run_id' => ProcessingRun::select('id')->whereColumn('driver_id', 'drivers.id')
                    ->where('type', RunType::EVALUATE->value)->orderByDesc('created_at')->orderByDesc('id')->limit(1),
                'last_check_at' => ProcessingRun::select('created_at')->whereColumn('driver_id', 'drivers.id')
                    ->where('type', RunType::EVALUATE->value)->orderByDesc('created_at')->orderByDesc('id')->limit(1),
                'latest_violations' => ComplianceFinding::selectRaw('count(*)')
                    ->whereColumn('compliance_findings.driver_id', 'drivers.id')
                    ->where('status', 'VIOLATION')
                    ->where('processing_run_id', $latestRun),
            ])
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('display_name', 'like', "%{$q}%")
                ->orWhere('external_id', 'like', "%{$q}%")))
            ->when($sort === 'last_check', fn ($query) => $query->orderBy('last_check_at', $direction))
            ->when($sort === 'violations', fn ($query) => $query->orderBy('latest_violations', $direction)->orderByDesc('last_check_at'))
            ->orderByDesc('is_active')
            ->orderBy('display_name')
            ->orderBy('external_id')
            ->paginate(25)
            ->withQueryString();

        $runs = ProcessingRun::query()
            ->whereIn('id', $drivers->pluck('latest_run_id')->filter())
            ->withCount(['complianceFindings as violations_count' => fn ($f) => $f->where('status', 'VIOLATION')])
            ->get()
            ->keyBy('id');

        return view('tachograph.drivers.index', [
            'drivers' => $drivers,
            'runs' => $runs,
            'q' => $q,
            'allowImport' => (bool) config('tachograph.allow_import'),
        ]);
    }

    public function sync(DriverSync $sync)
    {
        try {
            $stats = $sync->sync();
        } catch (MaponException $e) {
            return back()->with('error', __('Driver sync failed: :message', ['message' => $e->getMessage()]));
        }

        return back()->with('status', __(':created new, :updated updated, :deactivated deactivated drivers; :vehicles vehicles.', $stats));
    }

    /**
     * Finished evaluations grouped by report period (newest period first); the latest
     * report of each period is the main one, earlier re-runs are kept as older versions.
     *
     * @return Collection<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable, latest: ProcessingRun, older: Collection}>
     */
    private function reportsByPeriod(Driver $driver): Collection
    {
        $calendar = new WeekCalendar(config('tachograph.week_timezone', 'UTC'));

        return $driver->processingRuns()
            ->where('type', RunType::EVALUATE->value)
            ->where('status', RunStatus::DONE->value)
            ->whereNotNull('period_start')
            ->withCount([
                'complianceFindings as confirmed_count' => fn ($f) => $f->where('status', 'VIOLATION')->where('certainty', 'CONFIRMED'),
                'complianceFindings as potential_count' => fn ($f) => $f->where('status', 'VIOLATION')->where('certainty', 'POTENTIAL'),
                'complianceFindings as incomplete_count' => fn ($f) => $f->where('status', 'INCOMPLETE_DATA'),
            ])
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->groupBy(fn (ProcessingRun $r) => $r->period_start->toIso8601String().'|'.$r->period_end->toIso8601String())
            ->map(function (Collection $runs) use ($calendar) {
                $latest = $runs->first();
                $start = $latest->period_start->toImmutable();
                $end = $latest->period_end->toImmutable();
                $week = $calendar->weekOf($start);
                $isWeek = $week->start == $start && $week->end == $end;

                return [
                    'label' => $isWeek
                        ? __('Week :n, :year', ['n' => (int) substr($calendar->label($week), 6), 'year' => substr($calendar->label($week), 0, 4)])
                        : __(':days days', ['days' => (int) round($start->diffInDays($end))]),
                    'dates' => $start->format('j M').' – '.$end->subSecond()->format('j M Y'),
                    'start' => $start,
                    'latest' => $latest,
                    'older' => $runs->slice(1)->values(),
                ];
            })
            ->sortByDesc(fn (array $p) => [$p['start'], $p['latest']->period_end])
            ->values();
    }

    public function show(Driver $driver)
    {
        $tz = new DateTimeZone(config('tachograph.week_timezone', 'UTC'));
        $lastMonday = (new DateTimeImmutable('monday this week', $tz))->modify('-7 days');

        return view('tachograph.drivers.show', [
            'driver' => $driver,
            'reports' => $this->reportsByPeriod($driver),
            'runs' => $driver->processingRuns()
                ->withCount(['complianceFindings as violations_count' => fn ($f) => $f->where('status', 'VIOLATION')])
                ->latest()
                ->limit(15)
                ->get(),
            'dataFrom' => $driver->activityRecords()->min('start_at'),
            'dataUntil' => $driver->activityRecords()->max('end_at'),
            'defaultStart' => $lastMonday->format('Y-m-d'),
            'defaultEnd' => $lastMonday->modify('+6 days')->format('Y-m-d'),
        ]);
    }
}
