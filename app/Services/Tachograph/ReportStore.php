<?php

namespace App\Services\Tachograph;

use App\Models\ProcessingRun;
use App\Tachograph\Reporting\ReportData;
use Illuminate\Support\Facades\Storage;

/**
 * Report snapshots on the private disk: storage/app/private/reports/{run}.json
 */
class ReportStore
{
    public function path(ProcessingRun $run): string
    {
        return trim(config('tachograph.report_path'), '/')."/{$run->id}.json";
    }

    public function save(ProcessingRun $run, ReportData $report): void
    {
        Storage::disk('local')->put($this->path($run), json_encode($report->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function delete(ProcessingRun $run): void
    {
        Storage::disk('local')->delete($this->path($run));
    }

    public function load(ProcessingRun $run): ?ReportData
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($this->path($run))) {
            return null;
        }

        return ReportData::fromArray(json_decode($disk->get($this->path($run)), true, flags: JSON_THROW_ON_ERROR));
    }
}
