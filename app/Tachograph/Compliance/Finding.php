<?php

namespace App\Tachograph\Compliance;

use DateTimeImmutable;

final readonly class Finding
{
    /**
     * @param  list<string>  $relatedActivityIds  source event keys of the evidence records
     * @param  array<string, mixed>  $details  rule-specific values used by the reports
     */
    public function __construct(
        public string $rule,
        public FindingStatus $status,
        public Certainty $certainty,
        public Severity $severity,
        public string $driverId,
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
        public ?float $measuredValue,
        public ?float $allowedValue,
        public string $unit,
        public string $message,
        public array $relatedActivityIds = [],
        public array $details = [],
    ) {}

    public function isViolation(): bool
    {
        return $this->status === FindingStatus::VIOLATION;
    }

    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'status' => $this->status->value,
            'certainty' => $this->certainty->value,
            'severity' => $this->severity->value,
            'period_start' => $this->periodStart->format('Y-m-d\TH:i:s\Z'),
            'period_end' => $this->periodEnd->format('Y-m-d\TH:i:s\Z'),
            'measured_value' => $this->measuredValue,
            'allowed_value' => $this->allowedValue,
            'unit' => $this->unit,
            'message' => $this->message,
            'related_activity_ids' => $this->relatedActivityIds,
            'details' => $this->details,
        ];
    }
}
