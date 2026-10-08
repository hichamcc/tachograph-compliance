<?php

namespace App\Tachograph\Normalization;

/**
 * Data-quality issue types. These are never reported as legal violations.
 */
enum IssueType: string
{
    case MISSING_TIMESTAMP = 'MISSING_TIMESTAMP';
    case END_BEFORE_START = 'END_BEFORE_START';
    case ZERO_DURATION = 'ZERO_DURATION';
    case DUPLICATE_EVENT = 'DUPLICATE_EVENT';
    case OVERLAPPING_ACTIVITY = 'OVERLAPPING_ACTIVITY';
    case INVALID_ACTIVITY_TYPE = 'INVALID_ACTIVITY_TYPE';
    case MISSING_DRIVER_ID = 'MISSING_DRIVER_ID';
    case INCONSISTENT_ASSOCIATION = 'INCONSISTENT_ASSOCIATION';
    case TIMELINE_GAP = 'TIMELINE_GAP';
    case UNKNOWN_SOURCE_FILL = 'UNKNOWN_SOURCE_FILL';
    case TIMEZONE_INCONSISTENCY = 'TIMEZONE_INCONSISTENCY';
    case INVALID_TIMESTAMP = 'INVALID_TIMESTAMP';
    case NON_TACHOGRAPH_SOURCE = 'NON_TACHOGRAPH_SOURCE';
    case CARD_OUT_REST = 'CARD_OUT_REST';
    case CROSSCHECK_MISMATCH = 'CROSSCHECK_MISMATCH';

    public function defaultSeverity(): IssueSeverity
    {
        return match ($this) {
            self::MISSING_TIMESTAMP,
            self::END_BEFORE_START,
            self::MISSING_DRIVER_ID,
            self::INVALID_TIMESTAMP,
            self::TIMEZONE_INCONSISTENCY => IssueSeverity::ERROR,

            self::DUPLICATE_EVENT,
            self::ZERO_DURATION,
            self::NON_TACHOGRAPH_SOURCE,
            self::CARD_OUT_REST => IssueSeverity::INFO,

            default => IssueSeverity::WARNING,
        };
    }
}
