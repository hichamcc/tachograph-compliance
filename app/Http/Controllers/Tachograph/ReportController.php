<?php

namespace App\Http\Controllers\Tachograph;

use App\Http\Controllers\Controller;
use App\Models\ProcessingRun;
use App\RunStatus;
use App\RunType;
use App\Services\Tachograph\ReportStore;
use App\Tachograph\Data\Period;
use App\Tachograph\Periods\WeekCalendar;
use DateTimeImmutable;
use DateTimeZone;

class ReportController extends Controller
{
    public function show(ProcessingRun $run, ReportStore $reports)
    {
        $report = $reports->load($run);

        abort_if($report === null, 404);

        return view('tachograph.reports.show', [
            'run' => $run->load('driver'),
            'report' => $report,
            'weeks' => $this->weekNavigation($run, new Period(new DateTimeImmutable($report->period['start']), new DateTimeImmutable($report->period['end']))),
        ]);
    }

    /**
     * Previous / next fixed week, for reports covering exactly one week. Each side links to
     * that week's latest report, or carries the dates to run a check for it.
     *
     * @return array{previous: array, next: ?array}|null
     */
    private function weekNavigation(ProcessingRun $run, Period $period): ?array
    {
        $calendar = new WeekCalendar(config('tachograph.week_timezone', 'UTC'));
        $week = $calendar->weekOf($period->start);

        if ($week->start != $period->start || $week->end != $period->end) {
            return null;
        }

        $today = new DateTimeImmutable('today', new DateTimeZone(config('tachograph.week_timezone', 'UTC')));
        $next = $calendar->next($week);

        return [
            'previous' => $this->weekLink($run, $calendar, $calendar->previous($week)),
            'next' => $next->start <= $today ? $this->weekLink($run, $calendar, $next) : null,
        ];
    }

    private function weekLink(ProcessingRun $run, WeekCalendar $calendar, Period $week): array
    {
        $existing = ProcessingRun::query()
            ->where('driver_id', $run->driver_id)
            ->where('type', RunType::EVALUATE->value)
            ->where('status', RunStatus::DONE->value)
            ->where('period_start', $week->start->format('Y-m-d H:i:s'))
            ->where('period_end', $week->end->format('Y-m-d H:i:s'))
            ->orderByDesc('id')
            ->first();

        $tz = new DateTimeZone(config('tachograph.week_timezone', 'UTC'));
        $label = $calendar->label($week);

        return [
            'label' => __('Week :n', ['n' => (int) substr($label, 6)]),
            'run' => $existing,
            'start' => $week->start->setTimezone($tz)->format('Y-m-d'),
            'end' => $week->end->modify('-1 second')->setTimezone($tz)->format('Y-m-d'),
        ];
    }
}
