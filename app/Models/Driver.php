<?php

namespace App\Models;

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

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
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
