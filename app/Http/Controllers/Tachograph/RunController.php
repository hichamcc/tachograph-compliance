<?php

namespace App\Http\Controllers\Tachograph;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\ProcessingRun;
use App\Models\RawPayload;
use App\RunType;
use App\Services\Mapon\MaponException;
use App\Services\Tachograph\EvaluationService;
use App\Services\Tachograph\FetchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Throwable;

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

        // Without a queue worker (QUEUE_CONNECTION=sync) the download and check run inside
        // this request: give it time, and turn a Mapon failure into a failed run, not a 500.
        if (config('queue.default') === 'sync') {
            @set_time_limit(180);
        }

        try {
            $run = $fetch->start($driver, $period, $request->user());
        } catch (Throwable $e) {
            report($e);

            $run = ProcessingRun::where('driver_id', $driver->id)
                ->where('type', RunType::EVALUATE->value)
                ->orderByDesc('id')
                ->first();

            if ($run && ! $run->status->isFinished()) {
                $run->markFailed($e instanceof MaponException ? $e->getMessage() : __('The check failed. Please try again.'));
            }

            if (! $run) {
                return back()->with('error', __('The check failed. Please try again.'));
            }
        }

        RawPayload::pruneDaily();

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
