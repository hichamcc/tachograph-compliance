<?php

namespace App\Models;

use App\RunStatus;
use App\RunType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessingRun extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'type' => RunType::class,
            'status' => RunStatus::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function markRunning(): void
    {
        $this->update(['status' => RunStatus::RUNNING, 'started_at' => $this->started_at ?? now()]);
    }

    public function markDone(): void
    {
        $this->update(['status' => RunStatus::DONE, 'finished_at' => now()]);
    }

    /** The message must already be sanitized (no URLs, keys or personal data). */
    public function markFailed(string $safeMessage): void
    {
        $this->update(['status' => RunStatus::FAILED, 'error_message' => $safeMessage, 'finished_at' => now()]);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rawPayloads(): HasMany
    {
        return $this->hasMany(RawPayload::class);
    }

    public function validationIssues(): HasMany
    {
        return $this->hasMany(ValidationIssue::class);
    }

    public function complianceFindings(): HasMany
    {
        return $this->hasMany(ComplianceFinding::class);
    }
}
