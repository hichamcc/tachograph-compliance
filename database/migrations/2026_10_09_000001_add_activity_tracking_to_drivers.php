<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dateTime('last_active_at')->nullable()->after('is_active'); // end of the latest driving/work record
            $table->dateTime('last_fetched_at')->nullable()->after('last_active_at');
            $table->index('last_active_at');
        });

        // Backfill from data already stored.
        DB::statement("UPDATE drivers SET last_active_at = (
            SELECT MAX(end_at) FROM activity_records
            WHERE activity_records.driver_id = drivers.id AND activity_records.type IN ('DRIVING', 'WORK', 'AVAILABILITY')
        )");
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropIndex(['last_active_at']);
            $table->dropColumn(['last_active_at', 'last_fetched_at']);
        });
    }
};
