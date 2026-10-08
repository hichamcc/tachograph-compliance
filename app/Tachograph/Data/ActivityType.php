<?php

namespace App\Tachograph\Data;

enum ActivityType: string
{
    case DRIVING = 'DRIVING';
    case WORK = 'WORK';
    case AVAILABILITY = 'AVAILABILITY';
    case BREAK = 'BREAK';               // derived from REST
    case REST = 'REST';                 // unclassified rest (before classification)
    case DAILY_REST = 'DAILY_REST';     // derived
    case WEEKLY_REST = 'WEEKLY_REST';   // derived
    case UNKNOWN = 'UNKNOWN';

    public function isRestLike(): bool
    {
        return in_array($this, [self::BREAK, self::REST, self::DAILY_REST, self::WEEKLY_REST], true);
    }

    /** Daily and weekly rests end a shift (duty period). */
    public function endsShift(): bool
    {
        return $this === self::DAILY_REST || $this === self::WEEKLY_REST;
    }
}
