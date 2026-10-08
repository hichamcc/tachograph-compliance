<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class RawPayload extends Model
{
    protected $table = 'mapon_raw_payloads';

    protected function casts(): array
    {
        return [
            'chunk_from' => 'datetime',
            'chunk_till' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * Store the raw JSON body on the private disk and record it.
     */
    public static function store(string $endpoint, string $body, array $attributes = []): self
    {
        $sha = hash('sha256', $body);
        $path = trim(config('tachograph.raw_payload_path'), '/').'/'.now()->format('Y/m').'/'.str_replace('/', '_', $endpoint).'_'.now()->format('Ymd_His').'_'.substr($sha, 0, 12).'.json';

        Storage::disk('local')->put($path, $body);

        return self::create($attributes + [
            'endpoint' => $endpoint,
            'payload_path' => $path,
            'payload_sha256' => $sha,
            'fetched_at' => now(),
        ]);
    }

    /** Delete payload files and rows older than the retention period; returns the count. */
    public static function prune(int $days): int
    {
        $deleted = 0;

        self::where('fetched_at', '<', now()->subDays(max(1, $days)))->chunkById(200, function ($payloads) use (&$deleted) {
            foreach ($payloads as $payload) {
                Storage::disk('local')->delete($payload->payload_path);
                $payload->delete();
                $deleted++;
            }
        });

        return $deleted;
    }

    /** Prune at most once a day (used when no scheduler runs tacho:prune-raw). */
    public static function pruneDaily(): void
    {
        if (Cache::add('tachograph.raw_pruned', true, now()->addDay())) {
            self::prune((int) config('tachograph.raw_retention_days', 90));
        }
    }

    public function contents(): string
    {
        return Storage::disk('local')->get($this->payload_path);
    }

    public function decoded(): array
    {
        return json_decode($this->contents(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function processingRun(): BelongsTo
    {
        return $this->belongsTo(ProcessingRun::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
