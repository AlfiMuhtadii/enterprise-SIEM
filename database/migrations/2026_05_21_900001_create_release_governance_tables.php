<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // append-only: release lineage record
        Schema::create('release_manifests', function (Blueprint $table) {
            $table->id();
            $table->string('release_id', 64)->unique();
            $table->string('release_version', 64);
            $table->jsonb('component_versions')->default('{}');
            $table->jsonb('migration_versions')->default('[]');
            $table->jsonb('schema_versions')->default('{}');
            $table->string('replay_validator_version', 64)->nullable();
            $table->text('release_notes')->nullable();
            $table->string('rollback_reference', 64)->nullable();
            $table->string('manifest_hash', 64);
            $table->string('status', 32)->default('draft');
            $table->string('created_by', 128);
            $table->timestamps();
        });

        // append-only: preflight validation runs
        Schema::create('deployment_readiness_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 64)->unique();
            $table->string('release_id', 64)->nullable();
            $table->string('validator_status', 32)->default('pending');
            $table->boolean('migration_ready')->default(false);
            $table->boolean('schema_compatible')->default(false);
            $table->boolean('replay_validator_healthy')->default(false);
            $table->boolean('queue_healthy')->default(false);
            $table->boolean('storage_pressure_ok')->default(false);
            $table->boolean('degraded_mode_clear')->default(false);
            $table->boolean('worker_health_ok')->default(false);
            $table->boolean('rollback_ready')->default(false);
            $table->string('overall_verdict', 32)->default('not_ready');
            $table->jsonb('validation_details')->default('{}');
            $table->string('triggered_by', 128);
            $table->timestamps();
        });

        // append-only: environment drift evidence
        Schema::create('environment_drift_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id', 64)->unique();
            $table->string('release_id', 64)->nullable();
            $table->string('drift_type', 64);
            $table->string('component', 128);
            $table->string('expected_value', 512)->nullable();
            $table->string('observed_value', 512)->nullable();
            $table->string('severity', 32)->default('low');
            $table->boolean('is_blocking')->default(false);
            $table->string('detected_by', 128);
            $table->timestamps();
        });

        // append-only: rollback plan validation records
        Schema::create('rollback_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 64)->unique();
            $table->string('release_id', 64)->nullable();
            $table->string('rollback_target_version', 64);
            $table->boolean('migration_reversible')->default(false);
            $table->boolean('schema_rollback_safe')->default(false);
            $table->boolean('replay_safe')->default(false);
            $table->boolean('data_integrity_ok')->default(false);
            $table->decimal('readiness_score', 4, 2)->default(0.00);
            $table->string('verdict', 32)->default('not_ready');
            $table->jsonb('simulation_results')->default('{}');
            $table->string('validated_by', 128);
            $table->timestamps();
        });

        // append-only: release approval requests
        Schema::create('release_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 64)->unique();
            $table->string('release_id', 64);
            $table->string('readiness_run_id', 64)->nullable();
            $table->string('rollback_run_id', 64)->nullable();
            $table->text('readiness_summary')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('requested_by', 128);
            $table->timestamps();
        });

        // append-only: go/no-go decisions
        Schema::create('go_nogo_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('decision_id', 64)->unique();
            $table->string('request_id', 64);
            $table->string('release_id', 64);
            $table->string('decision', 16);
            $table->text('rationale')->nullable();
            $table->string('decided_by', 128);
            $table->timestamps();
        });

        // append-only: release audit trail
        Schema::create('release_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('release_id', 64)->nullable();
            $table->string('event_type', 64);
            $table->string('actor', 128);
            $table->jsonb('event_payload')->default('{}');
            $table->string('correlation_id', 64)->nullable();
            $table->timestamps();
        });

        // mutable: living runbook records (versioned via runbook_versions)
        Schema::create('operational_runbooks', function (Blueprint $table) {
            $table->id();
            $table->string('runbook_id', 64)->unique();
            $table->string('title', 255);
            $table->string('runbook_type', 64);
            $table->string('current_version', 32)->default('1.0.0');
            $table->string('owner', 128);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // append-only: immutable runbook version snapshots
        Schema::create('operational_runbook_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version_id', 64)->unique();
            $table->string('runbook_id', 64);
            $table->string('version', 32);
            $table->text('content');
            $table->string('content_hash', 64);
            $table->string('authored_by', 128);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_runbook_versions');
        Schema::dropIfExists('operational_runbooks');
        Schema::dropIfExists('release_audit_events');
        Schema::dropIfExists('go_nogo_decisions');
        Schema::dropIfExists('release_approval_requests');
        Schema::dropIfExists('rollback_validation_runs');
        Schema::dropIfExists('environment_drift_reports');
        Schema::dropIfExists('deployment_readiness_runs');
        Schema::dropIfExists('release_manifests');
    }
};
