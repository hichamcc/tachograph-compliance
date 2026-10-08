<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            // Mapon driver ID (numeric, as string) or a fixture ID such as "test_driver_01".
            $table->string('external_id')->unique();
            $table->string('origin')->default('mapon'); // mapon | local
            $table->string('display_name')->nullable();
            $table->string('card_number_hash', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique(); // Mapon unit ID
            $table->string('label')->nullable();
            $table->timestamps();
        });

        Schema::create('processing_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('driver_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // fetch | import | evaluate
            $table->dateTime('period_start')->nullable();
            $table->dateTime('period_end')->nullable();
            $table->string('status')->default('pending'); // pending | running | done | failed
            $table->unsignedInteger('records_retrieved')->default(0);
            $table->unsignedInteger('records_processed')->default(0);
            $table->unsignedInteger('records_invalid')->default(0);
            $table->unsignedInteger('findings_count')->default(0);
            $table->string('batch_id')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'created_at']);
        });

        Schema::create('mapon_raw_payloads', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('processing_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('endpoint');
            $table->dateTime('chunk_from')->nullable();
            $table->dateTime('chunk_till')->nullable();
            $table->string('payload_path');
            $table->string('payload_sha256', 64);
            $table->dateTime('fetched_at');
            $table->timestamps();
        });

        Schema::create('activity_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('raw_status')->nullable();
            $table->string('source'); // ddd | can | unkn | local
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->unsignedInteger('duration_seconds');
            $table->boolean('is_uncertain')->default(false);
            $table->string('source_event_key', 40);
            $table->foreignId('raw_payload_id')->nullable()->constrained('mapon_raw_payloads')->nullOnDelete();
            $table->foreignUlid('processing_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['driver_id', 'source_event_key']);
            $table->index(['driver_id', 'start_at']);
        });

        Schema::create('tacho_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // CARD_INSERTED | CARD_REMOVED | WORK_PERIOD_STARTED | WORK_PERIOD_FINISHED
            $table->dateTime('occurred_at');
            $table->string('source');
            $table->string('source_event_key', 40);
            $table->foreignId('raw_payload_id')->nullable()->constrained('mapon_raw_payloads')->nullOnDelete();
            $table->foreignUlid('processing_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['driver_id', 'source_event_key']);
            $table->index(['driver_id', 'occurred_at']);
        });

        Schema::create('validation_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('processing_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('severity'); // INFO | WARNING | ERROR
            $table->string('type');
            $table->dateTime('period_start')->nullable();
            $table->dateTime('period_end')->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();
        });

        Schema::create('compliance_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('processing_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->string('rule');
            $table->string('status');
            $table->string('certainty');
            $table->string('severity');
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->decimal('measured_value', 10, 2)->nullable();
            $table->decimal('allowed_value', 10, 2)->nullable();
            $table->string('unit');
            $table->text('message');
            $table->json('related_activity_ids')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_findings');
        Schema::dropIfExists('validation_issues');
        Schema::dropIfExists('tacho_events');
        Schema::dropIfExists('activity_records');
        Schema::dropIfExists('mapon_raw_payloads');
        Schema::dropIfExists('processing_runs');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('drivers');
    }
};
