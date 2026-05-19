<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // -----------------------------------------------------------------------
        // Retention governance
        // -----------------------------------------------------------------------

        Schema::create('retention_policies', function (Blueprint $table) {
            $table->id();
            $table->string('policy_id', 48)->unique()->index();
            $table->string('domain', 80)->index();                    // which domain/table
            $table->string('target_table', 120);                      // actual table name
            $table->string('time_column', 60)->default('created_at'); // column for age calculation
            $table->unsignedSmallInteger('retention_days');           // age threshold
            $table->unsignedInteger('max_rows')->nullable();          // row cap
            $table->unsignedSmallInteger('batch_size')->default(500); // bounded cleanup
            $table->string('status', 20)->default('dry_run_only');    // active|paused|dry_run_only
            $table->boolean('safe_archive_mode')->default(true);      // archive before delete
            $table->boolean('append_only_protected')->default(false); // block cleanup on append-only tables
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('retention_executions', function (Blueprint $table) {
            $table->id();
            $table->string('execution_id', 48)->unique()->index();
            $table->unsignedBigInteger('policy_id');
            $table->foreign('policy_id')->references('id')->on('retention_policies');
            $table->boolean('dry_run')->default(true);
            $table->unsignedInteger('rows_scanned')->default(0);
            $table->unsignedInteger('rows_eligible')->default(0);
            $table->unsignedInteger('rows_deleted')->default(0);   // always 0 for dry_run
            $table->unsignedInteger('execution_time_ms')->default(0);
            $table->string('status', 30)->default('dry_run');      // completed|dry_run|failed
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('retention_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('audit_id', 48)->unique()->index();
            $table->unsignedBigInteger('policy_id')->nullable();
            $table->string('execution_id', 48)->nullable()->index();
            $table->string('event_type', 60)->index();             // preview|dry_run|deletion|archive|policy_updated
            $table->string('domain', 80);
            $table->unsignedInteger('row_count')->default(0);
            $table->boolean('dry_run')->default(true);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->jsonb('details')->nullable();
            $table->string('trace_id', 48)->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });

        // -----------------------------------------------------------------------
        // Backup & recovery governance
        // -----------------------------------------------------------------------

        Schema::create('backup_metadata', function (Blueprint $table) {
            $table->id();
            $table->string('backup_id', 48)->unique()->index();
            $table->string('backup_type', 40)->index();            // full|incremental|snapshot|schema
            $table->string('scope', 120);                          // database|table|stream|config
            $table->timestamp('backup_at');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 128)->nullable();
            $table->string('storage_path', 1024)->nullable();
            $table->string('status', 30)->default('unknown');      // pending|verified|corrupted|unknown
            $table->text('validation_note')->nullable();
            $table->string('trace_id', 48)->nullable();
            $table->timestamps();
        });

        Schema::create('recovery_validations', function (Blueprint $table) {
            $table->id();
            $table->string('validation_id', 48)->unique()->index();
            $table->string('backup_id', 48)->index();
            $table->string('validation_type', 40)->index();        // integrity|replay|schema|checksum|row_count
            $table->string('result', 20)->index();                 // pass|fail|warning|skipped
            $table->jsonb('details')->nullable();
            $table->unsignedBigInteger('validated_by')->nullable();
            $table->string('trace_id', 48)->nullable();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });

        // -----------------------------------------------------------------------
        // DLQ operations
        // -----------------------------------------------------------------------

        Schema::create('dlq_replay_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_id', 48)->unique()->index();
            $table->string('topic', 120)->index();
            $table->string('source_partition', 30)->nullable();
            $table->unsignedBigInteger('from_offset')->default(0);
            $table->unsignedBigInteger('to_offset')->nullable();
            $table->unsignedSmallInteger('batch_limit')->default(100);// bounded replay
            $table->boolean('dry_run')->default(true);
            $table->string('status', 30)->default('pending');         // pending|simulated|executing|completed|failed|cancelled
            $table->string('deduplication_key', 128)->nullable();
            $table->unsignedInteger('rows_replayed')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);      // dedup skips
            $table->float('risk_score')->default(0.0);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('dlq_replay_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 48)->unique()->index();
            $table->string('job_id', 48)->index();
            $table->string('event_type', 60)->index();             // enqueued|simulated|replayed|skipped|failed
            $table->unsignedBigInteger('sequence_offset')->default(0);
            $table->boolean('dry_run')->default(true);
            $table->jsonb('details')->nullable();
            $table->string('trace_id', 48)->nullable();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });

        // -----------------------------------------------------------------------
        // Service & queue health observability
        // -----------------------------------------------------------------------

        Schema::create('service_health_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('service_name', 80)->index();
            $table->string('health_state', 20)->default('unknown')->index(); // healthy|degraded|down|unknown
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->boolean('dependencies_ok')->default(true);
            $table->jsonb('details')->nullable();
            $table->string('trace_id', 48)->nullable();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });

        Schema::create('queue_lag_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('consumer_group', 120)->index();
            $table->string('topic', 120)->index();
            $table->unsignedSmallInteger('partition')->default(0);
            $table->unsignedBigInteger('lag_count')->default(0);
            $table->unsignedSmallInteger('lag_age_seconds')->default(0);
            $table->string('trend', 20)->default('stable');       // increasing|stable|decreasing
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });

        Schema::create('stream_pressure_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('metric_type', 80)->index();            // throughput|backlog|saturation|replay_pressure
            $table->string('service_name', 80)->index();
            $table->float('value')->default(0.0);
            $table->string('unit', 30)->default('count');
            $table->float('threshold_warning')->nullable();
            $table->float('threshold_critical')->nullable();
            $table->string('state', 20)->default('normal')->index(); // normal|warning|critical
            $table->string('trace_id', 48)->nullable();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_pressure_metrics');
        Schema::dropIfExists('queue_lag_metrics');
        Schema::dropIfExists('service_health_snapshots');
        Schema::dropIfExists('dlq_replay_events');
        Schema::dropIfExists('dlq_replay_jobs');
        Schema::dropIfExists('recovery_validations');
        Schema::dropIfExists('backup_metadata');
        Schema::dropIfExists('retention_audit_events');
        Schema::dropIfExists('retention_executions');
        Schema::dropIfExists('retention_policies');
    }
};
