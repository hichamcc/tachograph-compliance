<?php

namespace App\Tachograph\Reporting;

use App\Tachograph\Aggregation\TotalsCalculator;
use App\Tachograph\Compliance\Certainty;
use App\Tachograph\Compliance\Finding;
use App\Tachograph\Compliance\FindingStatus;
use App\Tachograph\Compliance\RuleContext;
use App\Tachograph\Config\TachoConfig;
use App\Tachograph\Data\Activity;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Data\Period;
use App\Tachograph\Data\Timeline;
use App\Tachograph\Normalization\DataIssue;
use App\Tachograph\Normalization\IssueSeverity;
use App\Tachograph\Rules;
use DateTimeImmutable;
use DateTimeZone;

final class ReportBuilder
{
    private const STATUS_RANK = ['COMPLIANT' => 0, 'WARNING' => 1, 'INCOMPLETE_DATA' => 2, 'DATA_ERROR' => 3, 'VIOLATION' => 4];

    public function __construct(
        private readonly TachoConfig $config,
        private readonly TotalsCalculator $totals = new TotalsCalculator,
    ) {}

    /**
     * @param  list<Finding>  $findings
     * @param  list<DataIssue>  $issues
     */
    public function build(
        Timeline $timeline,
        Period $report,
        array $findings,
        array $issues,
        string $processingId,
        ?string $driverName = null,
        ?DateTimeImmutable $generatedAt = null,
    ): ReportData {
        $context = new RuleContext($timeline, $report, $this->config);

        return new ReportData(
            processingId: $processingId,
            generatedAt: self::iso($generatedAt ?? new DateTimeImmutable),
            driver: ['id' => $timeline->driverId, 'name' => $driverName],
            period: ['start' => self::iso($report->start), 'end' => self::iso($report->end)],
            displayTimezone: $this->config->displayTimezone,
            summary: $this->summary($context, $findings, $issues),
            daily: $this->daily($context, $findings),
            weekly: $this->weekly($context, $findings),
            findings: array_map(fn (Finding $f) => $f->toArray(), $findings),
            dataQuality: array_map(fn (DataIssue $i) => [
                'severity' => $i->severity->value,
                'type' => $i->type->value,
                'period_start' => $i->periodStart ? self::iso($i->periodStart) : null,
                'period_end' => $i->periodEnd ? self::iso($i->periodEnd) : null,
                'message' => $i->message,
            ], $issues),
            notEvaluated: $this->config->notEvaluated,
            activities: array_map(fn (Activity $a) => [
                'type' => $a->type->value,
                'start' => self::iso($a->start),
                'end' => self::iso($a->end),
                'duration_hours' => self::hours($a->durationSeconds()),
                'source' => $a->source->value,
                'uncertain' => $a->uncertain,
                'vehicle_id' => $a->vehicleId,
                'source_event_ids' => $a->sourceEventIds,
            ], $timeline->between($report)),
        );
    }

    private function summary(RuleContext $context, array $findings, array $issues): array
    {
        $totals = $this->totals->forPeriod($context, $context->report);
        $count = fn (FindingStatus $s, ?Certainty $c = null) => count(array_filter(
            $findings,
            fn (Finding $f) => $f->status === $s && ($c === null || $f->certainty === $c),
        ));

        $vehicles = array_values(array_unique(array_filter(array_map(
            fn (Activity $a) => $a->vehicleId,
            $context->timeline->between($context->report),
        ))));
        sort($vehicles);

        return [
            ...array_combine(
                array_map(fn ($g) => "total_{$g}_hours", TotalsCalculator::GROUPS),
                array_map(fn ($s) => self::hours($s), $totals),
            ),
            'vehicles' => $vehicles,
            'violations' => $count(FindingStatus::VIOLATION),
            'confirmed_violations' => $count(FindingStatus::VIOLATION, Certainty::CONFIRMED),
            'potential_violations' => $count(FindingStatus::VIOLATION, Certainty::POTENTIAL),
            'warnings' => $count(FindingStatus::WARNING),
            'incomplete_data' => $count(FindingStatus::INCOMPLETE_DATA),
            'compliant' => $count(FindingStatus::COMPLIANT),
            'data_quality_issues' => count(array_filter($issues, fn (DataIssue $i) => $i->severity !== IssueSeverity::INFO)),
            'data_until' => self::iso($context->horizon()),
        ];
    }

    /** One row per shift starting in the report period. */
    private function daily(RuleContext $context, array $findings): array
    {
        $tz = new DateTimeZone($this->config->displayTimezone);
        $rows = [];

        foreach ($context->shifts() as $shift) {
            if (! $context->inReport($shift->start)) {
                continue;
            }

            $totals = $this->totals->forShift($shift);
            $driving = $this->first($findings, Rules\DailyDrivingRule::CODE, fn (Finding $f) => $f->periodStart == $shift->start);
            $rest = $this->first($findings, Rules\DailyRestRule::CODE, fn (Finding $f) => $f->periodStart == $shift->start);
            $breaks = array_filter($findings, fn (Finding $f) => $f->rule === Rules\BreakRule::CODE
                && $f->periodStart < $shift->end && $f->periodEnd > $shift->start);

            $rows[] = [
                'date' => $shift->start->setTimezone($tz)->format('Y-m-d'),
                'shift_start' => self::iso($shift->start),
                'shift_end' => self::iso($shift->end),
                ...array_combine(
                    array_map(fn ($g) => "{$g}_hours", TotalsCalculator::GROUPS),
                    array_map(fn ($s) => self::hours($s), $totals),
                ),
                'following_rest_hours' => $shift->nextRest ? self::hours($shift->nextRest->durationSeconds()) : null,
                'limit_hours' => $driving?->details['limit_hours'] ?? null,
                'extended' => $driving?->details['extended'] ?? false,
                'daily_driving_status' => $driving?->status->value,
                'break_status' => $this->worst($breaks),
                'daily_rest_status' => $rest?->status->value,
                'daily_rest_type' => $rest?->details['rest_type'] ?? null,
            ];
        }

        return $rows;
    }

    /** One row per fixed week overlapping the report period. */
    private function weekly(RuleContext $context, array $findings): array
    {
        $regular = $this->config->hours('regular_weekly_rest_hours');
        $rows = [];

        foreach ($context->reportWeeks() as $week) {
            $atWeekStart = fn (Finding $f) => $f->periodStart == $week->start;
            $inWeek = fn (Finding $f) => $f->periodStart >= $week->start && $f->periodStart < $week->end;

            $driving = $this->first($findings, Rules\WeeklyDrivingRule::CODE, $atWeekStart);
            $extended = $this->first($findings, Rules\DailyDrivingRule::EXTENDED_CODE, $atWeekStart);
            $twoWeek = $this->first($findings, Rules\TwoWeekDrivingRule::CODE, fn (Finding $f) => $f->periodEnd == $week->end);
            $pattern = $this->first($findings, Rules\WeeklyRestRule::PATTERN_CODE, fn (Finding $f) => $f->periodEnd == $week->end);
            $compensation = array_values(array_filter($findings, fn (Finding $f) => $f->rule === Rules\WeeklyRestRule::COMPENSATION_CODE && $inWeek($f)));
            $deadlines = array_values(array_filter($findings, fn (Finding $f) => $f->rule === Rules\WeeklyRestRule::CODE
                && $f->periodStart < $week->end && $f->periodEnd > $week->start));
            $reduced = array_filter($findings, fn (Finding $f) => $f->rule === Rules\DailyRestRule::CODE
                && $inWeek($f) && ($f->details['rest_type'] ?? null) === 'reduced');

            $weeklyRests = array_values(array_filter(
                $context->timeline->activities(),
                fn (Activity $a) => $a->type === ActivityType::WEEKLY_REST && $a->start < $week->end && $a->end > $week->start,
            ));

            $rows[] = [
                'week' => $context->calendar->label($week),
                'start' => self::iso($week->start),
                'end' => self::iso($week->end),
                'driving_hours' => $driving?->measuredValue,
                'max_driving_hours' => $this->config->rules['max_weekly_driving_hours'],
                'driving_status' => $driving?->status->value,
                'extended_days_used' => $extended?->details['used'] ?? 0,
                'extended_days_allowed' => $this->config->count('maximum_extended_daily_driving_days_per_week'),
                'reduced_daily_rests' => count($reduced),
                'weekly_rests' => array_map(fn (Activity $r) => [
                    'type' => $r->durationSeconds() >= $regular ? 'regular' : 'reduced',
                    'start' => self::iso($r->start),
                    'end' => self::iso($r->end),
                    'hours' => self::hours($r->durationSeconds()),
                ], $weeklyRests),
                'weekly_rest_pattern_status' => $pattern?->status->value,
                'compensation' => array_map(fn (Finding $f) => [
                    'status' => $f->details['compensation_status'],
                    'owed_hours' => $f->details['owed_hours'],
                    'due_by' => $f->details['due_by'],
                ], $compensation),
                'two_week_driving_hours' => $twoWeek?->measuredValue,
                'two_week_status' => $twoWeek?->status->value,
                'status' => $this->worst(array_filter([$driving, $extended, $twoWeek, $pattern, ...$compensation, ...$deadlines])),
            ];
        }

        return $rows;
    }

    private function first(array $findings, string $rule, callable $match): ?Finding
    {
        foreach ($findings as $finding) {
            if ($finding->rule === $rule && $match($finding)) {
                return $finding;
            }
        }

        return null;
    }

    /** @param iterable<Finding> $findings */
    private function worst(iterable $findings): ?string
    {
        $worst = null;

        foreach ($findings as $finding) {
            $status = $finding->status->value;
            if ($worst === null || self::STATUS_RANK[$status] > self::STATUS_RANK[$worst]) {
                $worst = $status;
            }
        }

        return $worst;
    }

    private static function hours(int $seconds): float
    {
        return round($seconds / 3600, 2);
    }

    private static function iso(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
