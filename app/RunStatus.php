<?php

namespace App;

enum RunStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case DONE = 'done';
    case FAILED = 'failed';

    public function isFinished(): bool
    {
        return in_array($this, [self::DONE, self::FAILED], true);
    }
}
