<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Driver extends Model
{
    protected $hidden = ['card_number_hash'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_active_at' => 'datetime',
            'last_fetched_at' => 'datetime',
        ];
    }

    public static function hashCardNumber(?string $cardNumber): ?string
    {
        return $cardNumber ? hash_hmac('sha256', $cardNumber, config('app.key')) : null;
    }

    /** Pseudonymous identifier safe to write to logs. */
    public function logId(): string
    {
        return substr(hash_hmac('sha256', $this->external_id, config('app.key')), 0, 12);
    }

    public function label(): string
    {
        return $this->display_name ?: $this->external_id;
    }

    public function isMapon(): bool
    {
        return $this->origin === 'mapon';
    }

    /** Still present in Mapon. */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Present in Mapon and drove or worked within the last `active_weeks` weeks. */
    public function scopeRecentlyActive(Builder $query): void
    {
        $query->where('is_active', true)->where('last_active_at', '>=', self::activeSince());
    }

    public function scopeNotRecentlyActive(Builder $query): void
    {
        $query->where(fn ($q) => $q->where('is_active', false)
            ->orWhereNull('last_active_at')
            ->orWhere('last_active_at', '<', self::activeSince()));
    }

    public function isRecentlyActive(): bool
    {
        return $this->is_active && $this->last_active_at?->gte(self::activeSince());
    }

    public static function activeSince(): CarbonInterface
    {
        return now()->subWeeks((int) config('tachograph.active_weeks', 5));
    }

    /** Recompute last_active_at from stored driving/work/availability records. */
    public function refreshLastActive(): void
    {
        $this->update(['last_active_at' => $this->activityRecords()
            ->whereIn('type', ['DRIVING', 'WORK', 'AVAILABILITY'])
            ->max('end_at')]);
    }

    public function processingRuns(): HasMany
    {
        return $this->hasMany(ProcessingRun::class);
    }

    public function activityRecords(): HasMany
    {
        return $this->hasMany(ActivityRecord::class);
    }

    public function tachoEvents(): HasMany
    {
        return $this->hasMany(TachoEventRecord::class);
    }

    public function complianceFindings(): HasMany
    {
        return $this->hasMany(ComplianceFinding::class);
    }
}
