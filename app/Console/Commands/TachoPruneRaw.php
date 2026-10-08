<?php

namespace App\Console\Commands;

use App\Models\RawPayload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TachoPruneRaw extends Command
{
    protected $signature = 'tacho:prune-raw {--days=90 : Keep raw Mapon payloads for this many days}';

    protected $description = 'Delete raw API payload files older than the retention period';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) $this->option('days')));
        $deleted = 0;

        RawPayload::where('fetched_at', '<', $cutoff)->chunkById(200, function ($payloads) use (&$deleted) {
            foreach ($payloads as $payload) {
                Storage::disk('local')->delete($payload->payload_path);
                $payload->delete();
                $deleted++;
            }
        });

        $this->info("Deleted {$deleted} raw payload(s) older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
