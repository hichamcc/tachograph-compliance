<?php

namespace App\Http\Controllers\Tachograph;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\ProcessingRun;
use App\Services\Tachograph\EvaluationService;
use App\Services\Tachograph\FetchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

class RunController extends Controller
{
    /** Longest report period accepted from the UI (keeps jobs small on shared hosting). */
    public const MAX_DAYS = 62;

    public function store(Request $request, Driver $driver, FetchService $fetch, EvaluationService $evaluation)
    {
        $data = $request->validate([
            'start' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start', 'before_or_equal:today'],
        ]);

        $period = $evaluation->reportPeriod($data['start'], $data['end']);

        if ($period->durationSeconds() > self::MAX_DAYS * 86400) {
            return back()->withInput()->withErrors(['end' => __('The period may be at most :days days.', ['days' => self::MAX_DAYS])]);
        }

        $run = $fetch->start($driver, $period, $request->user());

        return redirect()->route('tachograph.runs.show', $run);
    }

    public function show(ProcessingRun $run)
    {
        $run->load('driver');
        $batch = $run->batch_id ? Bus::findBatch($run->batch_id) : null;

        return view('tachograph.runs.show', [
            'run' => $run,
            'batch' => $batch,
            'issues' => $run->status->isFinished()
                ? $run->validationIssues()->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity')
                : collect(),
        ]);
    }
}
