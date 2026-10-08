<?php

namespace App\Tachograph\Reporting;

/**
 * JSON report in the shape of the original specification (§11).
 */
final class JsonReport
{
    public function toArray(ReportData $report): array
    {
        return [
            'processing_id' => $report->processingId,
            'generated_at' => $report->generatedAt,
            'driver' => $report->driver,
            'period' => $report->period,
            'summary' => $report->summary,
            'daily' => $report->daily,
            'weekly' => $report->weekly,
            'violations' => $report->findingsWithStatus('VIOLATION'),
            'warnings' => $report->findingsWithStatus('WARNING'),
            'incomplete_data' => $report->findingsWithStatus('INCOMPLETE_DATA'),
            'compliant' => $report->findingsWithStatus('COMPLIANT'),
            'data_quality' => $report->dataQuality,
            'not_evaluated' => $report->notEvaluated,
            'disclaimer' => ReportData::DISCLAIMER,
        ];
    }

    public function render(ReportData $report): string
    {
        return json_encode($this->toArray($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
