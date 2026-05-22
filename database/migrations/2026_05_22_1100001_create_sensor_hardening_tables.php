<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // append-only: resource usage snapshots per endpoint
        Schema::create('sensor_resource_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_id', 64)->unique();
            $table->string('agent_id', 64);
            $table->string('host_id', 255)->nullable();
            $table->decimal('cpu_pct', 5, 2)->default(0.0);
            $table->integer('memory_mb')->default(0);
            $table->integer('spool_size_kb')->default(0);
            $table->integer('queue_depth')->default(0);
            $table->decimal('event_burst_rate', 8, 2)->default(0.0);
            $table->integer('disk_pressure_kb')->default(0);
            $table->string('pressure_state', 32)->default('normal');
            $table->timestamps();
        });

        // append-only: collector lifecycle state transitions
        Schema::create('collector_health_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('agent_id', 64);
            $table->string('host_id', 255)->nullable();
            $table->string('health_state', 32);
            $table->string('previous_state', 32)->nullable();
            $table->string('event_type', 64);
            $table->text('reason')->nullable();
            $table->boolean('operator_notified')->default(false);
            $table->timestamps();
        });

        // append-only: telemetry integrity validation runs
        Schema::create('telemetry_integrity_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 64)->unique();
            $table->string('agent_id', 64);
            $table->string('host_id', 255)->nullable();
            $table->boolean('checksum_valid')->default(true);
            $table->boolean('sequence_valid')->default(true);
            $table->boolean('replay_safe')->default(true);
            $table->integer('events_checked')->default(0);
            $table->integer('corruption_count')->default(0);
            $table->string('verdict', 32)->default('pass');
            $table->jsonb('integrity_details')->default('{}');
            $table->timestamps();
        });

        // append-only: detected telemetry gaps
        Schema::create('telemetry_gap_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id', 64)->unique();
            $table->string('agent_id', 64);
            $table->string('host_id', 255)->nullable();
            $table->integer('gap_duration_seconds')->default(0);
            $table->integer('estimated_lost_events')->default(0);
            $table->string('gap_reason', 128)->nullable();
            $table->boolean('recovered')->default(false);
            $table->boolean('replay_attempted')->default(false);
            $table->timestamp('gap_started_at')->nullable();
            $table->timestamp('gap_ended_at')->nullable();
            $table->timestamps();
        });

        // append-only: package signature validation records
        Schema::create('package_signature_validations', function (Blueprint $table) {
            $table->id();
            $table->string('validation_id', 64)->unique();
            $table->string('agent_id', 64)->nullable();
            $table->string('package_name', 255);
            $table->string('package_version', 64);
            $table->string('expected_hash', 128)->nullable();
            $table->string('observed_hash', 128)->nullable();
            $table->string('signer', 255)->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->boolean('hash_valid')->default(false);
            $table->string('verdict', 32)->default('unknown');
            $table->string('validated_by', 128);
            $table->timestamps();
        });

        // append-only: offline telemetry recovery runs
        Schema::create('offline_recovery_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 64)->unique();
            $table->string('agent_id', 64);
            $table->string('host_id', 255)->nullable();
            $table->integer('offline_duration_seconds')->default(0);
            $table->integer('buffered_event_count')->default(0);
            $table->integer('replayed_event_count')->default(0);
            $table->integer('dropped_event_count')->default(0);
            $table->boolean('replay_complete')->default(false);
            $table->boolean('sequence_continuity_ok')->default(false);
            $table->string('recovery_verdict', 32)->default('pending');
            $table->timestamps();
        });

        // append-only: collector restart audit records
        Schema::create('collector_restart_audit', function (Blueprint $table) {
            $table->id();
            $table->string('audit_id', 64)->unique();
            $table->string('agent_id', 64);
            $table->string('host_id', 255)->nullable();
            $table->string('restart_reason', 128)->nullable();
            $table->integer('restart_count_24h')->default(0);
            $table->boolean('operator_initiated')->default(false);
            $table->boolean('crash_induced')->default(false);
            $table->string('prior_health_state', 32)->nullable();
            $table->timestamps();
        });

        // append-only: telemetry sequence continuity validations
        Schema::create('telemetry_sequence_validations', function (Blueprint $table) {
            $table->id();
            $table->string('validation_id', 64)->unique();
            $table->string('agent_id', 64);
            $table->string('host_id', 255)->nullable();
            $table->integer('expected_sequence')->default(0);
            $table->integer('observed_sequence')->default(0);
            $table->integer('gap_count')->default(0);
            $table->integer('duplicate_count')->default(0);
            $table->boolean('continuity_ok')->default(true);
            $table->string('verdict', 32)->default('pass');
            $table->timestamps();
        });

        // append-only: endpoint agent upgrade validation records
        Schema::create('endpoint_upgrade_validations', function (Blueprint $table) {
            $table->id();
            $table->string('validation_id', 64)->unique();
            $table->string('agent_id', 64);
            $table->string('host_id', 255)->nullable();
            $table->string('from_version', 64);
            $table->string('to_version', 64);
            $table->boolean('package_verified')->default(false);
            $table->boolean('rollback_available')->default(false);
            $table->boolean('telemetry_resumed')->default(false);
            $table->string('verdict', 32)->default('pending');
            $table->string('validated_by', 128);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_upgrade_validations');
        Schema::dropIfExists('telemetry_sequence_validations');
        Schema::dropIfExists('collector_restart_audit');
        Schema::dropIfExists('offline_recovery_runs');
        Schema::dropIfExists('package_signature_validations');
        Schema::dropIfExists('telemetry_gap_reports');
        Schema::dropIfExists('telemetry_integrity_runs');
        Schema::dropIfExists('collector_health_events');
        Schema::dropIfExists('sensor_resource_snapshots');
    }
};
