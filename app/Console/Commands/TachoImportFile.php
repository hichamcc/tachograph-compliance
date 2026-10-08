<?php

namespace App\Console\Commands;

use App\Services\Tachograph\ImportService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class TachoImportFile extends Command
{
    protected $signature = 'tacho:import-file
        {path : JSON file (local fixture format or a raw Mapon daily_activities response)}
        {--format=local : local | mapon}
        {--driver= : Driver ID (required for --format=mapon)}';

    protected $description = 'Import tachograph activities from a local JSON file';

    public function handle(ImportService $import): int
    {
        $path = $this->resolvePath($this->argument('path'));

        if ($path === null) {
            $this->error('File not found.');

            return self::INVALID;
        }

        try {
            $run = $import->import(file_get_contents($path), (string) $this->option('format'), $this->option('driver') ?: null);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        } catch (Throwable $e) {
            report($e);
            $this->error('Import failed: '.class_basename($e));

            return self::FAILURE;
        }

        $drivers = $run->driver_id ? [$run->driver->external_id] : '(several)';

        $this->info("Processing run {$run->id}");
        $this->table(['Driver', 'Retrieved', 'Processed', 'Invalid'], [[
            is_array($drivers) ? implode(', ', $drivers) : $drivers,
            $run->records_retrieved,
            $run->records_processed,
            $run->records_invalid,
        ]]);

        $issues = $run->validationIssues()->selectRaw('severity, type, count(*) as n')->groupBy('severity', 'type')->orderBy('severity')->get();

        if ($issues->isNotEmpty()) {
            $this->table(['Severity', 'Data-quality issue', 'Count'], $issues->map(fn ($i) => [$i->severity, $i->type, $i->n])->all());
        }

        return self::SUCCESS;
    }

    private function resolvePath(string $path): ?string
    {
        foreach ([$path, base_path($path)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
