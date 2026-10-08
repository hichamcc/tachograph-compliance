<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    public function activityRecords(): HasMany
    {
        return $this->hasMany(ActivityRecord::class);
    }
}
