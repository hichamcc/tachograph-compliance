<?php

namespace App\Tachograph\Data;

enum ActivitySource: string
{
    case DDD = 'ddd';       // driver card file — authoritative
    case CAN = 'can';       // vehicle CAN bus — no driver card evidence
    case UNKNOWN = 'unkn';  // gap filled by Mapon or by us
    case LOCAL = 'local';   // local JSON fixture

    /** Higher wins when two records overlap. */
    public function priority(): int
    {
        return match ($this) {
            self::DDD, self::LOCAL => 3,
            self::CAN => 2,
            self::UNKNOWN => 1,
        };
    }

    public static function weakest(self $a, self $b): self
    {
        return $a->priority() <= $b->priority() ? $a : $b;
    }
}
