<?php

namespace App\Tachograph\Reporting;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Presentation helpers for HTML reports.
 */
final class Format
{
    /** Rules whose message adds something beyond the measured value and limit. */
    private const RULES_WITH_NOTES = ['DAILY_REST', 'WEEKLY_REST', 'WEEKLY_REST_PATTERN', 'WEEKLY_REST_COMPENSATION'];

    /** 8.75 → "8h45" */
    public static function hm(?float $hours): string
    {
        if ($hours === null) {
            return '–';
        }

        $minutes = (int) round(abs($hours) * 60);

        return ($hours < 0 ? '−' : '').sprintf('%dh%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** UTC ISO string → local display string. */
    public static function local(?string $iso, string $timezone, string $format = 'D j M H:i'): string
    {
        return $iso ? self::time($iso, $timezone)->format($format) : '–';
    }

    public static function utc(?string $iso): string
    {
        return $iso ? (new DateTimeImmutable($iso))->format('Y-m-d H:i').' UTC' : '';
    }

    /** "Fri 2 Oct 03:01 → 17:57", "Fri 2 Oct 03:01 → Sat 05:01", "Mon 28 Sep → Mon 5 Oct" */
    public static function range(string $startIso, string $endIso, string $timezone): string
    {
        $start = self::time($startIso, $timezone);
        $end = self::time($endIso, $timezone);
        $days = ($end->getTimestamp() - $start->getTimestamp()) / 86400;

        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            return $start->format('D j M H:i').' → '.$end->format('H:i');
        }

        return $days < 6
            ? $start->format('D j M H:i').' → '.$end->format('D H:i')
            : $start->format('D j M').' → '.$end->format('D j M');
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'VIOLATION', 'DATA_ERROR' => 'red',
            'INCOMPLETE_DATA' => 'amber',
            'WARNING' => 'yellow',
            'COMPLIANT' => 'green',
            default => 'gray',
        };
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            null => '–',
            'INCOMPLETE_DATA' => 'Not enough data',
            'DATA_ERROR' => 'Data error',
            'COMPLIANT' => 'OK',
            default => ucfirst(strtolower($status)),
        };
    }

    /** Status text for one finding, saying what a warning actually is. */
    public static function findingLabel(array $finding, string $timezone): string
    {
        return match (true) {
            $finding['status'] === 'VIOLATION' && $finding['certainty'] === 'POTENTIAL' => 'Potential violation',
            $finding['status'] === 'WARNING' && $finding['rule'] === 'DAILY_DRIVING_LIMIT' => 'Extended day',
            $finding['status'] === 'WARNING' && $finding['rule'] === 'WEEKLY_REST_COMPENSATION' => 'Due '.self::local($finding['period_end'], $timezone, 'D j M H:i'),
            default => self::statusLabel($finding['status']),
        };
    }

    /** "12h04 / 10h00", "3 / 2", "11h00 owed" */
    public static function value(array $finding): string
    {
        $hours = $finding['unit'] === 'hours';
        $show = fn ($v) => $hours ? self::hm($v) : (string) (int) $v;

        if ($finding['measured_value'] === null) {
            return '–';
        }

        if ($finding['allowed_value'] === null) {
            return $show($finding['measured_value']).($finding['rule'] === 'WEEKLY_REST_COMPENSATION' ? ' owed' : '');
        }

        return $show($finding['measured_value']).' / '.$show($finding['allowed_value']);
    }

    /** "+2h04" over a maximum or "−4h36" short of a minimum, for violations only. */
    public static function difference(array $finding): ?string
    {
        if ($finding['status'] !== 'VIOLATION' || $finding['measured_value'] === null || $finding['allowed_value'] === null) {
            return null;
        }

        $diff = $finding['measured_value'] - $finding['allowed_value'];

        if ($finding['unit'] !== 'hours') {
            return sprintf('%+d', $diff);
        }

        return ($diff > 0 ? '+' : '').self::hm($diff);
    }

    /** The finding message, only where it adds information. */
    public static function note(array $finding, ?string $timezone = null): ?string
    {
        if ($timezone && $finding['rule'] === 'WEEKLY_REST_COMPENSATION' && $finding['status'] === 'WARNING' && isset($finding['details']['with_weekly_rest_start_by'])) {
            return self::compensationPlan($finding, $timezone);
        }

        return $finding['status'] === 'INCOMPLETE_DATA' || in_array($finding['rule'], self::RULES_WITH_NOTES, true)
            ? $finding['message']
            : null;
    }

    /** Rule groups for the report's rule filter, in display order. */
    public const RULE_GROUPS = [
        'breaks' => 'Breaks',
        'daily_driving' => 'Daily driving',
        'weekly_driving' => 'Weekly driving',
        'daily_rest' => 'Daily rest',
        'weekly_rest' => 'Weekly rest',
    ];

    /** Status filter key: violation | potential | incomplete | warning | ok */
    public static function statusKey(array $finding): string
    {
        return match ($finding['status']) {
            'VIOLATION' => $finding['certainty'] === 'POTENTIAL' ? 'potential' : 'violation',
            'INCOMPLETE_DATA' => 'incomplete',
            'WARNING' => 'warning',
            default => 'ok',
        };
    }

    public static function ruleGroup(string $rule): string
    {
        return match ($rule) {
            'BREAK_AFTER_4_5_HOURS' => 'breaks',
            'DAILY_DRIVING_LIMIT', 'EXTENDED_DAYS_PER_WEEK' => 'daily_driving',
            'WEEKLY_DRIVING_LIMIT', 'TWO_WEEK_DRIVING_LIMIT' => 'weekly_driving',
            'DAILY_REST', 'DAILY_REST_REDUCTIONS' => 'daily_rest',
            default => 'weekly_rest',
        };
    }

    /**
     * "Start break latest Fri 9 Oct 08:10 — 65h50 (45h weekly rest + 20h50 compensation), finished by
     * Mon 12 Oct 02:00. Or: start latest Sat 10 Oct 20:10 with a daily rest of 29h50 (9h + 20h50)."
     * Options whose start has passed are left out.
     */
    public static function compensationPlan(array $finding, string $timezone, ?DateTimeImmutable $now = null): string
    {
        $d = $finding['details'];
        $now ??= new DateTimeImmutable;
        $owed = self::hm($d['owed_hours']);
        $deadline = self::local($finding['period_end'], $timezone, 'D j M H:i');
        $open = fn (string $key) => new DateTimeImmutable($d[$key]) > $now;
        $at = fn (string $key) => self::local($d[$key], $timezone, 'D j M H:i');
        $weekly = self::hm($d['with_weekly_rest_hours']);
        $daily = self::hm($d['with_daily_rest_hours']);

        return match (true) {
            $open('with_weekly_rest_start_by') && $open('with_daily_rest_start_by') => "Start break latest {$at('with_weekly_rest_start_by')} — {$weekly} (45h weekly rest + {$owed} compensation), finished by {$deadline}. "
                ."Or: start latest {$at('with_daily_rest_start_by')} with a daily rest of {$daily} (9h + {$owed}).",
            $open('with_daily_rest_start_by') => "Start break latest {$at('with_daily_rest_start_by')} — daily rest of {$daily} (9h + {$owed} compensation), finished by {$deadline}.",
            default => "Not enough time left: {$owed} compensation in one block with a rest of at least 9h had to be finished by {$deadline}.",
        };
    }

    public static function rule(string $rule): string
    {
        return ucfirst(strtolower(str_replace(['_4_5_', '_'], [' 4.5 ', ' '], $rule)));
    }

    public static function activity(string $type): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', $type)));
    }

    public static function source(string $source): string
    {
        return match ($source) {
            'ddd' => 'card',
            'can' => 'vehicle',
            'unkn' => 'no data',
            default => 'file',
        };
    }

    public static function runType(string $type): string
    {
        return match ($type) {
            'fetch' => 'Download',
            'evaluate' => 'Check',
            'crosscheck' => 'Cross-check',
            'import' => 'Import',
            default => ucfirst($type),
        };
    }

    private static function time(string $iso, string $timezone): DateTimeImmutable
    {
        return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone($timezone));
    }
}
