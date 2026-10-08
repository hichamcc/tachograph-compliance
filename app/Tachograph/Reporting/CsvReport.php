<?php

namespace App\Tachograph\Reporting;

use InvalidArgumentException;

/**
 * CSV exports (UTF-8 with BOM so Excel opens them correctly).
 */
final class CsvReport
{
    public const KINDS = ['findings', 'daily', 'weekly', 'activities'];

    /** @param resource $handle */
    public function write(string $kind, ReportData $report, $handle): void
    {
        [$header, $rows] = match ($kind) {
            'findings' => $this->findings($report),
            'daily' => $this->daily($report),
            'weekly' => $this->weekly($report),
            'activities' => $this->activities($report),
            default => throw new InvalidArgumentException("Unknown CSV kind [{$kind}]."),
        };

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $header, escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::cell(...), $row), escape: '');
        }
    }

    public function render(string $kind, ReportData $report): string
    {
        $handle = fopen('php://temp', 'r+');
        $this->write($kind, $report, $handle);
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function filename(string $kind, ReportData $report): string
    {
        return sprintf('%s_%s_%s.csv', $kind, preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $report->driver['id']), substr($report->period['start'], 0, 10));
    }

    private function findings(ReportData $report): array
    {
        $header = ['rule', 'status', 'certainty', 'severity', 'period_start', 'period_end', 'measured_value', 'allowed_value', 'unit', 'message', 'related_activity_ids'];

        return [$header, array_map(fn (array $f) => [
            $f['rule'], $f['status'], $f['certainty'], $f['severity'], $f['period_start'], $f['period_end'],
            $f['measured_value'], $f['allowed_value'], $f['unit'], $f['message'], implode(' ', $f['related_activity_ids']),
        ], $report->findings)];
    }

    private function daily(ReportData $report): array
    {
        $header = ['date', 'shift_start', 'shift_end', 'driving_hours', 'work_hours', 'availability_hours', 'break_hours', 'rest_hours', 'unknown_hours',
            'following_rest_hours', 'limit_hours', 'extended', 'daily_driving_status', 'break_status', 'daily_rest_status', 'daily_rest_type'];

        return [$header, array_map(fn (array $row) => array_map(fn ($col) => $row[$col] ?? null, $header), $report->daily)];
    }

    private function weekly(ReportData $report): array
    {
        $header = ['week', 'start', 'end', 'driving_hours', 'max_driving_hours', 'driving_status', 'extended_days_used', 'extended_days_allowed',
            'reduced_daily_rests', 'weekly_rests', 'weekly_rest_pattern_status', 'compensation', 'two_week_driving_hours', 'two_week_status', 'status'];

        return [$header, array_map(fn (array $row) => array_map(fn ($col) => match ($col) {
            'weekly_rests' => implode('; ', array_map(fn ($r) => "{$r['type']} {$r['hours']}h from {$r['start']}", $row['weekly_rests'])),
            'compensation' => implode('; ', array_map(fn ($c) => "{$c['status']} {$c['owed_hours']}h due {$c['due_by']}", $row['compensation'])),
            default => $row[$col] ?? null,
        }, $header), $report->weekly)];
    }

    private function activities(ReportData $report): array
    {
        $header = ['type', 'start', 'end', 'duration_hours', 'source', 'uncertain', 'vehicle_id', 'source_event_ids'];

        return [$header, array_map(fn (array $a) => [
            $a['type'], $a['start'], $a['end'], $a['duration_hours'], $a['source'], $a['uncertain'], $a['vehicle_id'], implode(' ', $a['source_event_ids']),
        ], $report->activities)];
    }

    private static function cell(mixed $value): string
    {
        $value = match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            $value === null => '',
            default => (string) $value,
        };

        // Neutralise spreadsheet formula injection.
        return preg_match('/^[=+\-@\t\r]/', $value) && ! is_numeric($value) ? "'".$value : $value;
    }
}
