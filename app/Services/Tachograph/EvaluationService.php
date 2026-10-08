<?php

namespace App\Services\Tachograph;

use App\Models\ComplianceFinding;
use App\Models\Driver;
use App\Models\ProcessingRun;
use App\Models\User;
use App\Models\ValidationIssue;
use App\RunStatus;
use App\RunType;
use App\Tachograph\Compliance\ComplianceEngine;
use App\Tachograph\Compliance\Finding;
use App\Tachograph\Config\TachoConfig;
use App\Tachograph\Data\Activity;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Data\Period;
use App\Tachograph\Data\Timeline;
use App\Tachograph\Normalization\DataIssue;
use App\Tachograph\Normalization\DataValidator;
use App\Tachograph\Normalization\IssueSeverity;
use App\Tachograph\Normalization\IssueType;
use App\Tachograph\Normalization\TimelineBuilder;
use App\Tachograph\Reporting\ReportBuilder;
use App\Tachograph\Reporting\ReportData;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Loads a driver's stored activities (incl. history), evaluates them and stores
 * findings, data-quality issues and the report snapshot under one processing run.
 */
class EvaluationService
{
    public function __construct(
        private readonly TachoConfig $config,
        private readonly ActivityStore $activities,
        private readonly TimelineBuilder $timelineBuilder,
        private readonly DataValidator $validator,
        private readonly ComplianceEngine $engine,
        private readonly ReportBuilder $reportBuilder,
        private readonly ReportStore $reports,
    ) {}

    /** Inclusive dates (Y-m-d) in the week timezone → half-open UTC period. */
    public function reportPeriod(string $startDate, string $endDate): Period
    {
        $tz = new DateTimeZone($this->config->weekTimezone);
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, $tz);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, $tz);

        if (! $start || ! $end || $end < $start) {
            throw new \InvalidArgumentException('Invalid report period; expected start <= end as Y-m-d.');
        }

        $utc = new DateTimeZone('UTC');

        return new Period($start->setTimezone($utc), $end->modify('+1 day')->setTimezone($utc));
    }

    /** Window of data needed to evaluate the report period (history for 2-week/compensation rules). */
    public function dataWindow(Period $report): Period
    {
        $history = (int) config('tachograph.history_days', 28);

        return new Period($report->start->modify("-{$history} days"), $report->end->modify('+1 day'));
    }

    public function evaluate(Driver $driver, Period $report, ?User $user = null, ?ProcessingRun $run = null): ProcessingRun
    {
        $run ??= ProcessingRun::create([
            'type' => RunType::EVALUATE,
            'status' => RunStatus::PENDING,
            'driver_id' => $driver->id,
            'user_id' => $user?->id,
        ]);

        $run->update(['period_start' => $report->start, 'period_end' => $report->end]);
        $run->markRunning();

        try {
            [$timeline, $activities] = $this->timeline($driver, $report);
            $issues = $this->withoutSupersededFillers($this->issues($driver, $report, $activities), $timeline);
            $findings = $this->engine->evaluate($timeline, $report);

            $data = $this->reportBuilder->build($timeline, $report, $findings, $issues, $run->id, $driver->display_name, new DateTimeImmutable);

            DB::transaction(function () use ($run, $driver, $findings, $issues, $activities, $data) {
                $this->storeFindings($run, $driver, $findings);
                $this->storeIssues($run, $driver, $issues);
                $this->reports->save($run, $data);

                $run->update([
                    'records_processed' => count($activities),
                    'findings_count' => count($findings),
                ]);
            });

            $run->markDone();
        } catch (Throwable $e) {
            $run->markFailed('Evaluation failed ('.class_basename($e).').');
            Log::channel('tachograph')->error('Evaluation failed', ['processing_id' => $run->id, 'driver' => $driver->logId(), 'exception' => class_basename($e)]);

            throw $e;
        }

        Log::channel('tachograph')->info('Evaluation finished', [
            'processing_id' => $run->id,
            'driver' => $driver->logId(),
            'period' => [$report->start->format('c'), $report->end->format('c')],
            'records_processed' => $run->records_processed,
            'findings' => $run->findings_count,
            'violations' => $data->summary['violations'],
        ]);

        return $run;
    }

    /**
     * Timeline for the report period incl. history, built from stored activities.
     *
     * @return array{Timeline, list<Activity>}
     */
    public function timeline(Driver $driver, Period $report): array
    {
        $window = $this->dataWindow($report);
        $activities = $this->activities->load($driver, $window);

        return [$this->timelineBuilder->build($driver->external_id, $activities, $this->coverage($driver, $window, $activities)), $activities];
    }

    /**
     * Mapon data is complete up to "now" within the fetched window; for local imports the
     * data ends with the last record.
     *
     * @param  list<Activity>  $activities
     */
    private function coverage(Driver $driver, Period $window, array $activities): Period
    {
        $end = $driver->isMapon()
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : ($activities ? max(array_map(fn (Activity $a) => $a->end, $activities)) : $window->start);

        return new Period($window->start, max($window->start, min($window->end, $end)));
    }

    /**
     * Data-quality issues for the report period: those recorded when the data was imported or
     * fetched (incl. rejected records) plus timeline checks on the data as stored now.
     *
     * @param  list<Activity>  $activities
     * @return list<DataIssue>
     */
    private function issues(Driver $driver, Period $report, array $activities): array
    {
        $issues = [];
        $utc = new DateTimeZone('UTC');

        $stored = ValidationIssue::query()
            ->where('driver_id', $driver->id)
            ->whereHas('processingRun', fn ($q) => $q->where('type', '!=', RunType::EVALUATE->value))
            ->where(fn ($q) => $q->whereNull('period_start')->orWhere(fn ($q) => $q
                ->where('period_start', '<', $report->end->format('Y-m-d H:i:s'))
                ->whereRaw('coalesce(period_end, period_start) >= ?', [$report->start->format('Y-m-d H:i:s')])))
            ->orderBy('period_start')
            ->get();

        foreach ($stored as $row) {
            if ($type = IssueType::tryFrom($row->type)) {
                $issues[] = new DataIssue(
                    $type,
                    IssueSeverity::from($row->severity),
                    $row->message,
                    $driver->external_id,
                    $row->period_start ? new DateTimeImmutable($row->getRawOriginal('period_start'), $utc) : null,
                    $row->period_end ? new DateTimeImmutable($row->getRawOriginal('period_end'), $utc) : null,
                    $row->context ?? [],
                );
            }
        }

        foreach ($this->validator->validate($activities) as $issue) {
            if ($issue->periodStart === null || ($issue->periodStart < $report->end && ($issue->periodEnd ?? $issue->periodStart) >= $report->start)) {
                $issues[] = $issue;
            }
        }

        // The same issue may have been recorded by several imports/fetches.
        $unique = [];
        foreach ($issues as $issue) {
            $unique[implode('|', [$issue->type->value, $issue->periodStart?->getTimestamp(), $issue->periodEnd?->getTimestamp()])] ??= $issue;
        }

        $unique = array_values($unique);
        usort($unique, fn (DataIssue $a, DataIssue $b) => $a->periodStart <=> $b->periodStart);

        return $unique;
    }

    /**
     * Drop "gap filler" warnings for periods where real data (e.g. a later driver-card download)
     * overlaps the filler and won in the timeline.
     *
     * @param  list<DataIssue>  $issues
     * @return list<DataIssue>
     */
    private function withoutSupersededFillers(array $issues, Timeline $timeline): array
    {
        return array_values(array_filter($issues, function (DataIssue $issue) use ($timeline) {
            if ($issue->type !== IssueType::UNKNOWN_SOURCE_FILL || ! $issue->periodStart || ! $issue->periodEnd || $issue->periodEnd <= $issue->periodStart) {
                return true;
            }

            return $timeline->secondsOf(ActivityType::UNKNOWN, new Period($issue->periodStart, $issue->periodEnd)) > 0;
        }));
    }

    /** @param list<Finding> $findings */
    private function storeFindings(ProcessingRun $run, Driver $driver, array $findings): void
    {
        $now = now()->format('Y-m-d H:i:s');

        $rows = array_map(fn (Finding $f) => [
            'processing_run_id' => $run->id,
            'driver_id' => $driver->id,
            'rule' => $f->rule,
            'status' => $f->status->value,
            'certainty' => $f->certainty->value,
            'severity' => $f->severity->value,
            'period_start' => $f->periodStart->format('Y-m-d H:i:s'),
            'period_end' => $f->periodEnd->format('Y-m-d H:i:s'),
            'measured_value' => $f->measuredValue,
            'allowed_value' => $f->allowedValue,
            'unit' => $f->unit,
            'message' => $f->message,
            'related_activity_ids' => json_encode($f->relatedActivityIds),
            'created_at' => $now,
            'updated_at' => $now,
        ], $findings);

        foreach (array_chunk($rows, 500) as $chunk) {
            ComplianceFinding::insert($chunk);
        }
    }

    /** @param list<DataIssue> $issues */
    private function storeIssues(ProcessingRun $run, Driver $driver, array $issues): void
    {
        $now = now()->format('Y-m-d H:i:s');

        $rows = array_map(fn (DataIssue $i) => [
            'processing_run_id' => $run->id,
            'driver_id' => $driver->id,
            'severity' => $i->severity->value,
            'type' => $i->type->value,
            'period_start' => $i->periodStart?->format('Y-m-d H:i:s'),
            'period_end' => $i->periodEnd?->format('Y-m-d H:i:s'),
            'message' => $i->message,
            'context' => $i->context ? json_encode($i->context) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $issues);

        foreach (array_chunk($rows, 500) as $chunk) {
            ValidationIssue::insert($chunk);
        }
    }

    public function report(ProcessingRun $run): ?ReportData
    {
        return $this->reports->load($run);
    }
}
