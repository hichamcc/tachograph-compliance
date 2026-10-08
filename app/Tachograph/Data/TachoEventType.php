<?php

namespace App\Tachograph\Data;

enum TachoEventType: string
{
    case CARD_INSERTED = 'CARD_INSERTED';
    case CARD_REMOVED = 'CARD_REMOVED';
    case WORK_PERIOD_STARTED = 'WORK_PERIOD_STARTED';
    case WORK_PERIOD_FINISHED = 'WORK_PERIOD_FINISHED';
}
