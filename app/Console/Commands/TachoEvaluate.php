<?php

namespace App\Console\Commands;

use App\Models\Driver;
use App\Services\Tachograph\EvaluationService;
use App\Services\Tachograph\HtmlReport;
use App\Tachograph\Reporting\CsvReport;
use App\Tachograph\Reporting\JsonReport;
use Illuminate\Console\Command;
use InvalidArgumentException;

class TachoEvaluate extends Command
{
    protected $signature = 'tacho:evaluate
        {--driver= : Driver ID (Mapon driver ID or fixture ID)}
        {--start= : First day of the report (Y-m-d)}
        {--end= : Last day of the report, inclusive (Y-m-d)}
        {--output= : Output file (default: storage/app/private/reports/<run>.json)}
        {--format=json : Comma-separated: json,csv,html}';

    protected $description = 'Evaluate stored activities against the driving/rest rules and write a report';

    public function handle(EvaluationService $service, JsonReport $json, CsvReport $csv, HtmlReport $html): int
    {
        $formats = array_filter(array_map('trim', explode(',', (string) $this->option('format'))));

        if ($formats === [] || array_diff($formats, ['json', 'csv', 'html'])) {
            $this->error('--format must be a comma-separated list of json, csv, html.');

            return self::INVALID;
        }

        if (! $this->option('driver') || ! $this->option('start') || ! $this->option('end')) {
            $this->error('--driver, --start and --end are required.');

            return self::INVALID;
        }

        $driver = Driver::where('external_id', $this->option('driver'))->first();

        if (! $driver) {
            $this->error('Unknown driver. Import or sync it first.');

            return self::INVALID;
        }

        try {
            $period = $service->reportPeriod($this->option('start'), $this->option('end'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $run = $service->evaluate($driver, $period);
        $report = $service->report($run);

        $output = $this->option('output') ?: storage_path('app/private/'.trim(config('tachograph.report_path'), '/')."/{$run->id}.json");
        $base = preg_replace('/\.(json|csv|html)$/i', '', $output);
        if (! is_dir(dirname($base))) {
            mkdir(dirname($base), 0755, true);
        }

        $written = [];

        if (in_array('json', $formats, true)) {
            file_put_contents($written[] = "{$base}.json", $json->render($report));
        }

        if (in_array('csv', $formats, true)) {
            foreach (CsvReport::KINDS as $kind) {
                file_put_contents($written[] = "{$base}_{$kind}.csv", $csv->render($kind, $report));
            }
        }

        if (in_array('html', $formats, true)) {
            file_put_contents($written[] = "{$base}.html", $html->render($report));
        }

        $s = $report->summary;
        $this->info("Processing run {$run->id}");
        $this->table(
            ['Driving', 'Work', 'Rest', 'Unknown', 'Violations', 'Potential', 'Warnings', 'Incomplete', 'Data issues'],
            [[$s['total_driving_hours'].'h', $s['total_work_hours'].'h', $s['total_rest_hours'].'h', $s['total_unknown_hours'].'h',
                $s['confirmed_violations'], $s['potential_violations'], $s['warnings'], $s['incomplete_data'], $s['data_quality_issues']]],
        );

        foreach ($report->findingsWithStatus('VIOLATION') as $finding) {
            $this->line("  <fg=red>VIOLATION</> {$finding['rule']} {$finding['period_start']} – {$finding['message']}");
        }

        foreach ($written as $file) {
            $this->line("Wrote {$file}");
        }

        return self::SUCCESS;
    }
}
