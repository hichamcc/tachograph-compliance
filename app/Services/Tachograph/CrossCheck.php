<?php

namespace App\Services\Tachograph;

use App\Models\ActivityRecord;
use App\Models\Driver;
use App\Models\ProcessingRun;
use App\Models\ValidationIssue;
use App\RunStatus;
use App\RunType;
use App\Services\Mapon\MaponClient;
use App\Tachograph\Compliance\ComplianceEngine;
use App\Tachograph\Compliance\Finding;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Data\Period;
use App\Tachograph\Normalization\IssueSeverity;
use App\Tachograph\Normalization\IssueType;
use App\Tachograph\Periods\WeekCalendar;
use App\Tachograph\Rules;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;

/**
 * Compares our values for the current fixed week with Mapon's live counters
 * (unit_data/driving_time_extended). A test aid, not a rule: differences are recorded
 * as CROSSCHECK_MISMATCH data-quality notes. Only numeric counters are read — the
 * response also contains names and card numbers, which are ignored.
 */
class CrossCheck
{
    public function __construct(
        private readonly MaponClient $mapon,
        private readonly EvaluationService $evaluation,
        private readonly ComplianceEngine $engine,
    ) {}

    /**
     * @return array{run: ProcessingRun, rows: list<array{metric: string, unit: string, mapon: ?float, ours: ?float, match: ?bool}>, data_until: ?string, unit_found: bool}
     */
    public function check(Driver $driver): array
    {
        $run = ProcessingRun::create([
            'type' => RunType::CROSSCHECK,
            'status' => RunStatus::RUNNING,
            'driver_id' => $driver->id,
            'started_at' => now(),
        ]);

        $counters = $this->maponCounters($driver);
        $ours = $this->ourCounters($driver, $week);
        $tolerance = (int) config('tachograph.crosscheck_tolerance_minutes', 30) / 60;

        $rows = [];
        foreach ([
            ['week_driving', 'hours', 'Driving this week'],
            ['previous_week_driving', 'hours', 'Driving previous week'],
            ['extensions_used', 'count', '10h extensions used this week'],
            ['rest_reductions_used', 'count', 'Reduced daily rests since weekly rest'],
        ] as [$key, $unit, $label]) {
            $mapon = $counters[$key] ?? null;
            $value = $ours[$key] ?? null;
            $match = $mapon === null || $value === null ? null
                : ($unit === 'hours' ? abs($mapon - $value) <= $tolerance : $mapon == $value);

            $rows[] = ['metric' => $label, 'key' => $key, 'unit' => $unit, 'mapon' => $mapon, 'ours' => $value, 'match' => $match];

            if ($match === false) {
                ValidationIssue::create([
                    'processing_run_id' => $run->id,
                    'driver_id' => $driver->id,
                    'severity' => IssueSeverity::INFO->value,
                    'type' => IssueType::CROSSCHECK_MISMATCH->value,
                    'period_start' => $week->start,
                    'period_end' => $week->end,
                    'message' => sprintf('%s: Mapon %s, ours %s.', $label, $mapon, $value),
                    'context' => ['metric' => $key, 'mapon' => $mapon, 'ours' => $value],
                ]);
            }
        }

        $run->update([
            'period_start' => $week->start,
            'period_end' => $week->end,
            'findings_count' => count(array_filter($rows, fn ($r) => $r['match'] === false)),
        ]);
        $run->markDone();

        Log::channel('tachograph')->info('Cross-check finished', [
            'processing_id' => $run->id,
            'driver' => $driver->logId(),
            'unit_found' => $counters !== null,
            'mismatches' => $run->findings_count,
        ]);

        return ['run' => $run, 'rows' => $rows, 'data_until' => $ours['data_until'] ?? null, 'unit_found' => $counters !== null];
    }

    /** Counters for this driver from the vehicle they used most recently. */
    private function maponCounters(Driver $driver): ?array
    {
        $unitId = ActivityRecord::where('driver_id', $driver->id)
            ->whereNotNull('vehicle_id')
            ->latest('end_at')
            ->with('vehicle:id,external_id')
            ->first()?->vehicle?->external_id;

        if ($unitId === null) {
            return null;
        }

        $data = $this->mapon->getDrivingTimeExtended((int) $unitId);

        foreach ($data as $slot) {
            if (is_array($slot) && (string) ($slot['driver_id'] ?? '') === $driver->external_id) {
                $week = $slot['week'] ?? [];

                return [
                    'week_driving' => isset($week['driving']) ? round($week['driving'] / 3600, 2) : null,
                    'previous_week_driving' => isset($week['previous_week_driving']) ? round($week['previous_week_driving'] / 3600, 2) : null,
                    'extensions_used' => isset($week['10h_driving_extensions_used']) ? (float) $week['10h_driving_extensions_used'] : null,
                    'rest_reductions_used' => isset($week['9h_rest_shortening_used']) ? (float) $week['9h_rest_shortening_used'] : null,
                ];
            }
        }

        return null; // driver is no longer on that vehicle
    }

    private function ourCounters(Driver $driver, ?Period &$week): array
    {
        $calendar = new WeekCalendar(config('tachograph.week_timezone', 'UTC'));
        $week = $calendar->weekOf(new DateTimeImmutable('now', new DateTimeZone('UTC')));

        [$timeline] = $this->evaluation->timeline($driver, $week);
        $findings = $this->engine->evaluate($timeline, $week);

        $first = fn (string $rule) => current(array_filter($findings, fn (Finding $f) => $f->rule === $rule)) ?: null;
        $dailyRests = array_values(array_filter($findings, fn (Finding $f) => $f->rule === Rules\DailyRestRule::CODE && isset($f->details['reductions_used'])));

        return [
            'week_driving' => $first(Rules\WeeklyDrivingRule::CODE)?->measuredValue,
            'previous_week_driving' => round($timeline->secondsOf(ActivityType::DRIVING, $calendar->previous($week)) / 3600, 2),
            'extensions_used' => $first(Rules\DailyDrivingRule::EXTENDED_CODE)?->measuredValue,
            'rest_reductions_used' => $dailyRests ? (float) end($dailyRests)->details['reductions_used'] : null,
            'data_until' => $timeline->coverage()?->end->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
