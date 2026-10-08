<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
