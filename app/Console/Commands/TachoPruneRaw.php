<?php

namespace App\Console\Commands;

use App\Models\RawPayload;
use Illuminate\Console\Command;

class TachoPruneRaw extends Command
{
    protected $signature = 'tacho:prune-raw {--days=90 : Keep raw Mapon payloads for this many days}';

    protected $description = 'Delete raw API payload files older than the retention period';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $deleted = RawPayload::prune($days);

        $this->info("Deleted {$deleted} raw payload(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
