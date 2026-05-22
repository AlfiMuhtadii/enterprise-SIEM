<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: deployment package manifests
        Schema::create('deployment_package_manifests', function (Blueprint $table) {
            $table->id();
            $table->string('manifest_id')->unique();
            $table->string('package_id');
            $table->string('version');
            $table->string('package_hash_sha256');
            $table->string('image_digest')->nullable();
            $table->boolean('is_signed')->default(false);
            $table->boolean('signature_valid')->default(false);
            $table->boolean('dependency_integrity_verified')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('manifest_payload')->nullable();
            $table->timestamps();
        });

        // Append-only: deployment integrity reports
        Schema::create('deployment_integrity_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('package_id');
            $table->string('version');
            $table->boolean('validation_passed')->default(false);
            $table->boolean('hash_match')->default(false);
            $table->boolean('signature_valid')->default(false);
            $table->boolean('dependency_drift_detected')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('integrity_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: rollout validation runs
        Schema::create('rollout_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->string('rollout_id');
            $table->string('stage'); // canary, staged, full
            $table->integer('canary_endpoints')->default(0);
            $table->boolean('rollout_success')->default(false);
            $table->boolean('checkpoint_passed')->default(false);
            $table->boolean('bounded_concurrency')->default(true);
            $table->float('rollout_duration_s')->default(0.0);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('rollout_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: deployment upgrade history
        Schema::create('deployment_upgrade_history', function (Blueprint $table) {
            $table->id();
            $table->string('upgrade_id')->unique();
            $table->string('from_version');
            $table->string('to_version');
            $table->boolean('migration_passed')->default(false);
            $table->boolean('rollback_compatible')->default(false);
            $table->boolean('dependency_drift_detected')->default(false);
            $table->boolean('compatibility_verified')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('upgrade_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: environment validation reports
        Schema::create('environment_validation_reports', function (Blueprint $table) {
            $table->id();
            $table->string('validation_id')->unique();
            $table->string('environment'); // local, staging, production
            $table->boolean('services_healthy')->default(false);
            $table->boolean('queue_available')->default(false);
            $table->boolean('storage_available')->default(false);
            $table->boolean('replay_continuous')->default(false);
            $table->boolean('telemetry_continuous')->default(false);
            $table->boolean('overall_valid')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('validation_details')->nullable();
            $table->timestamps();
        });

        // Append-only: deployment drift reports
        Schema::create('deployment_drift_reports', function (Blueprint $table) {
            $table->id();
            $table->string('drift_id')->unique();
            $table->string('service');
            $table->string('expected_version');
            $table->string('actual_version');
            $table->boolean('drift_detected')->default(false);
            $table->string('drift_severity')->default('low'); // low, medium, high, critical
            $table->float('drift_score')->default(0.0);
            $table->boolean('is_advisory')->default(true);
            $table->json('drift_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: deployment observability snapshots
        Schema::create('deployment_observability_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_id')->unique();
            $table->string('deployment_id');
            $table->float('success_rate')->default(0.0);
            $table->float('rollout_duration_s')->default(0.0);
            $table->float('restart_frequency')->default(0.0);
            $table->float('deployment_latency_ms')->default(0.0);
            $table->boolean('replay_continuity_verified')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('observability_payload')->nullable();
            $table->timestamps();
        });

        // Append-only: rollout checkpoint history
        Schema::create('rollout_checkpoint_history', function (Blueprint $table) {
            $table->id();
            $table->string('checkpoint_id')->unique();
            $table->string('rollout_id');
            $table->string('checkpoint_type');
            $table->boolean('passed')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('validation_details')->nullable();
            $table->timestamps();
        });

        // Append-only: enterprise deployment audit
        Schema::create('enterprise_deployment_audit', function (Blueprint $table) {
            $table->id();
            $table->string('audit_id')->unique();
            $table->string('deployment_id');
            $table->string('action_type');
            $table->string('actor');
            $table->string('outcome');
            $table->boolean('is_advisory')->default(true);
            $table->json('audit_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_deployment_audit');
        Schema::dropIfExists('rollout_checkpoint_history');
        Schema::dropIfExists('deployment_observability_snapshots');
        Schema::dropIfExists('deployment_drift_reports');
        Schema::dropIfExists('environment_validation_reports');
        Schema::dropIfExists('deployment_upgrade_history');
        Schema::dropIfExists('rollout_validation_runs');
        Schema::dropIfExists('deployment_integrity_reports');
        Schema::dropIfExists('deployment_package_manifests');
    }
};
