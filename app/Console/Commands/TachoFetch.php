<?php

namespace App\Console\Commands;

use App\Models\Driver;
use App\Services\Tachograph\EvaluationService;
use App\Services\Tachograph\FetchService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use InvalidArgumentException;

class TachoFetch extends Command
{
    protected $signature = 'tacho:fetch
        {--driver=* : Mapon driver ID(s)}
        {--all : All active Mapon drivers}
        {--start= : First report day (Y-m-d)}
        {--end= : Last report day, inclusive (Y-m-d)}
        {--since= : Relative start, e.g. "yesterday" or "-7 days" (report ends yesterday)}
        {--no-evaluate : Only fetch, do not evaluate}';

    protected $description = 'Fetch driver activities from Mapon (queued, one job per 28-day chunk) and evaluate them';

    public function handle(FetchService $fetch, EvaluationService $evaluation): int
    {
        try {
            [$start, $end] = $this->reportDates();
            $period = $evaluation->reportPeriod($start, $end);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $query = Driver::query()->where('origin', 'mapon');
        $query = $this->option('all')
            ? $query->active()
            : $query->whereIn('external_id', (array) $this->option('driver'));

        $drivers = $query->orderBy('id')->get();

        if ($drivers->isEmpty()) {
            $this->error($this->option('all') ? 'No active Mapon drivers. Run tacho:sync-drivers first.' : 'Pass --driver=ID (synced Mapon driver) or --all.');

            return self::INVALID;
        }

        foreach ($drivers as $driver) {
            $run = $fetch->start($driver, $period, evaluate: ! $this->option('no-evaluate'));
            $this->line("Driver {$driver->external_id}: run {$run->id} ({$run->status->value})");
        }

        $this->info(sprintf('Queued %d driver(s) for %s – %s.', $drivers->count(), $start, $end));

        return self::SUCCESS;
    }

    /** @return array{string, string} */
    private function reportDates(): array
    {
        $tz = new DateTimeZone(config('tachograph.week_timezone', 'UTC'));

        if ($since = $this->option('since')) {
            $start = (new DateTimeImmutable($since, $tz))->format('Y-m-d');
            $end = (new DateTimeImmutable('yesterday', $tz))->format('Y-m-d');

            return [min($start, $end), $end];
        }

        if (! $this->option('start') || ! $this->option('end')) {
            throw new InvalidArgumentException('Pass --start and --end, or --since.');
        }

        return [$this->option('start'), $this->option('end')];
    }
}
