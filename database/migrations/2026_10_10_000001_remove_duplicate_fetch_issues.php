<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data-problem notes from Mapon downloads were also stored once more without a driver
 * (numeric driver IDs became array ints). Remove those copies; reports never used them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('validation_issues')
            ->whereNull('driver_id')
            ->whereIn('processing_run_id', DB::table('processing_runs')->where('type', 'fetch')->select('id'))
            ->delete();
    }

    public function down(): void
    {
        //
    }
};
