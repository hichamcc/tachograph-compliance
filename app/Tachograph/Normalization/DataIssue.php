<?php

namespace App\Tachograph\Normalization;

use DateTimeImmutable;

final readonly class DataIssue
{
    public function __construct(
        public IssueType $type,
        public IssueSeverity $severity,
        public string $message,
        public ?string $driverId = null,
        public ?DateTimeImmutable $periodStart = null,
        public ?DateTimeImmutable $periodEnd = null,
        public array $context = [],
    ) {}

    public static function make(
        IssueType $type,
        string $message,
        ?string $driverId = null,
        ?DateTimeImmutable $periodStart = null,
        ?DateTimeImmutable $periodEnd = null,
        array $context = [],
        ?IssueSeverity $severity = null,
    ): self {
        return new self($type, $severity ?? $type->defaultSeverity(), $message, $driverId, $periodStart, $periodEnd, $context);
    }
}
