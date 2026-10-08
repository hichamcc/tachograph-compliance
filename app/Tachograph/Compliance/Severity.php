<?php

namespace App\Tachograph\Compliance;

enum Severity: string
{
    case LOW = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH = 'HIGH';

    public static function forStatus(FindingStatus $status): self
    {
        return match ($status) {
            FindingStatus::VIOLATION, FindingStatus::DATA_ERROR => self::HIGH,
            FindingStatus::WARNING, FindingStatus::INCOMPLETE_DATA => self::MEDIUM,
            FindingStatus::COMPLIANT => self::LOW,
        };
    }
}
