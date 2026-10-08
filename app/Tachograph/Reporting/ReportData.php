<?php

namespace App\Tachograph\Reporting;

/**
 * Serializable snapshot of one evaluation. JSON, CSV and HTML are all rendered from it,
 * so the formats always agree. Times are UTC ISO-8601 strings.
 */
final readonly class ReportData
{
    public const DISCLAIMER = 'Compliance-support report. Not an official legal determination.';

    public function __construct(
        public string $processingId,
        public string $generatedAt,
        public array $driver,          // ['id' => ..., 'name' => ...]
        public array $period,          // ['start' => ..., 'end' => ...] (end exclusive)
        public string $displayTimezone,
        public array $summary,
        public array $daily,
        public array $weekly,
        public array $findings,        // Finding::toArray() rows, sorted
        public array $dataQuality,
        public array $notEvaluated,
        public array $activities,
    ) {}

    /** @return list<array> findings with the given status */
    public function findingsWithStatus(string ...$statuses): array
    {
        return array_values(array_filter($this->findings, fn (array $f) => in_array($f['status'], $statuses, true)));
    }

    public function toArray(): array
    {
        return [
            'processing_id' => $this->processingId,
            'generated_at' => $this->generatedAt,
            'driver' => $this->driver,
            'period' => $this->period,
            'display_timezone' => $this->displayTimezone,
            'summary' => $this->summary,
            'daily' => $this->daily,
            'weekly' => $this->weekly,
            'findings' => $this->findings,
            'data_quality' => $this->dataQuality,
            'not_evaluated' => $this->notEvaluated,
            'activities' => $this->activities,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            processingId: $data['processing_id'],
            generatedAt: $data['generated_at'],
            driver: $data['driver'],
            period: $data['period'],
            displayTimezone: $data['display_timezone'],
            summary: $data['summary'],
            daily: $data['daily'],
            weekly: $data['weekly'],
            findings: $data['findings'],
            dataQuality: $data['data_quality'],
            notEvaluated: $data['not_evaluated'],
            activities: $data['activities'],
        );
    }
}
