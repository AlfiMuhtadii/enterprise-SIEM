<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mutable — updated on each heartbeat tick, tracks live worker state
        Schema::create('system_worker_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('worker_id', 80)->unique()->index();
            $table->string('service_name', 80)->index();
            $table->string('consumer_group', 120)->nullable()->index();
            $table->jsonb('assigned_partitions')->nullable();
            $table->unsignedBigInteger('processed_event_count')->default(0);
            $table->unsignedBigInteger('failed_event_count')->default(0);
            $table->unsignedBigInteger('retry_count')->default(0);
            $table->unsignedBigInteger('current_lag')->default(0);
            // health_state: healthy, degraded, lagging, stalled, disconnected
            $table->string('health_state', 40)->default('healthy')->index();
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->boolean('is_stale')->default(false);
            $table->boolean('is_stalled')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        // Append-only — richer consumer lag snapshot correlated to workers
        Schema::create('stream_consumer_lag_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_id', 40)->unique()->index();
            $table->string('consumer_group', 120)->index();
            $table->string('topic', 120)->index();
            $table->integer('partition')->default(0);
            $table->string('worker_id', 80)->nullable()->index();
            $table->unsignedBigInteger('lag_count')->default(0);
            $table->integer('lag_age_seconds')->default(0);
            $table->unsignedBigInteger('poison_message_count')->default(0);
            $table->integer('idempotency_violations')->default(0);
            $table->integer('retry_budget_remaining')->default(100);
            // trend: increasing, stable, decreasing
            $table->string('trend', 40)->default('stable');
            // pressure_state: normal, warning, critical
            $table->string('pressure_state', 40)->default('normal')->index();
            $table->boolean('dlq_growing')->default(false);
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Mutable — current throttle config + state per topic/group
        Schema::create('replay_throttle_states', function (Blueprint $table) {
            $table->id();
            $table->string('throttle_id', 40)->unique()->index();
            $table->string('consumer_group', 120)->index();
            $table->string('topic', 120)->nullable()->index();
            $table->integer('max_events_per_second')->default(100);
            $table->integer('retry_budget_limit')->default(50);
            $table->integer('current_retry_count')->default(0);
            // state: normal, throttled, exhausted
            $table->string('state', 40)->default('normal')->index();
            $table->boolean('is_active')->default(true);
            $table->timestamp('throttle_started_at')->nullable();
            $table->timestamp('throttle_cleared_at')->nullable();
            $table->timestamps();
        });

        // Mutable — idempotency tracking per event fingerprint
        Schema::create('idempotency_validation_records', function (Blueprint $table) {
            $table->id();
            $table->string('record_id', 40)->unique()->index();
            $table->string('event_fingerprint', 64)->unique()->index();
            $table->string('source_topic', 120)->nullable()->index();
            $table->string('trace_id', 120)->nullable()->index();
            $table->string('event_id', 120)->nullable()->index();
            // processing_state: first_seen, processed, replayed_safe, duplicate_detected
            $table->string('processing_state', 40)->default('first_seen')->index();
            $table->boolean('is_duplicate')->default(false);
            $table->unsignedBigInteger('replay_count')->default(0);
            $table->boolean('corruption_risk')->default(false);
            $table->timestamps();
        });

        // Append-only — duplicate event detection records
        Schema::create('duplicate_event_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id', 40)->unique()->index();
            $table->string('event_fingerprint', 64)->index();
            $table->string('source_topic', 120)->nullable()->index();
            $table->string('trace_id', 120)->nullable()->index();
            $table->string('original_event_id', 120)->nullable()->index();
            $table->string('duplicate_event_id', 120)->nullable()->index();
            $table->text('detection_reason')->nullable();
            // severity: informational, warning, critical
            $table->string('severity', 40)->default('informational')->index();
            $table->boolean('suppressed')->default(false);
            $table->boolean('analytics_protected')->default(true);
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Append-only — per-backend storage pressure snapshots
        Schema::create('storage_pressure_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_id', 40)->unique()->index();
            // backend: postgresql, clickhouse, opensearch, redpanda, qdrant
            $table->string('backend', 40)->index();
            $table->float('retention_pressure_pct')->default(0.0);
            $table->bigInteger('index_size_bytes')->nullable();
            $table->float('ingestion_lag_seconds')->nullable();
            $table->float('write_failure_rate')->nullable();
            $table->float('query_latency_ms')->nullable();
            $table->float('compaction_pressure_pct')->nullable();
            // pressure_state: normal, warning, critical
            $table->string('pressure_state', 40)->default('normal')->index();
            $table->boolean('degraded')->default(false);
            $table->jsonb('details')->nullable();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Append-only — degraded mode state transition audit
        Schema::create('degraded_mode_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 40)->unique()->index();
            $table->string('service_name', 80)->index();
            // degraded_reason: dependency_unavailable, storage_write_degraded, stream_lag_degraded,
            //   worker_stalled, replay_throttled, dlq_pressure, search_unavailable, ai_retrieval_unavailable
            $table->string('degraded_reason', 80)->index();
            // event_type: entered, cleared, validation_required
            $table->string('event_type', 40)->default('entered')->index();
            $table->text('description')->nullable();
            $table->boolean('operator_acknowledged')->default(false);
            $table->boolean('auto_cleared')->default(false);
            $table->string('trace_id', 120)->nullable()->index();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Append-only — structured recovery validation run records
        Schema::create('recovery_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 40)->unique()->index();
            // scenario: service_restart, replay_resume, dlq_bounded, idempotency_check, trace_propagation
            $table->string('scenario', 80)->index();
            $table->string('triggered_by', 80)->nullable();
            // status: running, passed, failed, warning
            $table->string('status', 40)->default('running')->index();
            $table->integer('total_checks')->default(0);
            $table->integer('passed_checks')->default(0);
            $table->integer('failed_checks')->default(0);
            $table->boolean('trace_propagation_ok')->default(false);
            $table->boolean('replay_idempotency_ok')->default(false);
            $table->boolean('worker_stale_detection_ok')->default(false);
            $table->jsonb('check_results')->nullable();
            $table->text('failure_summary')->nullable();
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_validation_runs');
        Schema::dropIfExists('degraded_mode_events');
        Schema::dropIfExists('storage_pressure_snapshots');
        Schema::dropIfExists('duplicate_event_reports');
        Schema::dropIfExists('idempotency_validation_records');
        Schema::dropIfExists('replay_throttle_states');
        Schema::dropIfExists('stream_consumer_lag_snapshots');
        Schema::dropIfExists('system_worker_heartbeats');
    }
};
