<?php

namespace App\Http\Controllers\Tachograph;

use App\Http\Controllers\Controller;
use App\Models\ProcessingRun;
use App\Services\Tachograph\HtmlReport;
use App\Services\Tachograph\ReportStore;
use App\Tachograph\Reporting\CsvReport;
use App\Tachograph\Reporting\JsonReport;

class ExportController extends Controller
{
    public function show(ProcessingRun $run, string $format, ReportStore $reports, ?string $kind = null)
    {
        $report = $reports->load($run);

        abort_if($report === null, 404);

        $base = sprintf('tachograph_%s_%s', preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $report->driver['id']), substr($report->period['start'], 0, 10));

        return match ($format) {
            'json' => response(app(JsonReport::class)->render($report), 200, [
                'Content-Type' => 'application/json',
                'Content-Disposition' => "attachment; filename=\"{$base}.json\"",
            ]),
            'csv' => $this->csv($report, $kind ?? 'findings'),
            'html' => response(app(HtmlReport::class)->render($report), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$base}.html\"",
            ]),
            default => abort(404),
        };
    }

    private function csv($report, string $kind)
    {
        abort_unless(in_array($kind, CsvReport::KINDS, true), 404);

        $csv = app(CsvReport::class);

        return response()->streamDownload(
            fn () => $csv->write($kind, $report, fopen('php://output', 'w')),
            $csv->filename($kind, $report),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
