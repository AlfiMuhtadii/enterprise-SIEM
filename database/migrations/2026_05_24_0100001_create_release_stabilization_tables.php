<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: release candidate manifests
        Schema::create('release_candidate_manifests', function (Blueprint $table) {
            $table->id();
            $table->string('manifest_id')->unique();
            $table->string('rc_version'); // e.g. "1.0.0-rc.1"
            $table->string('rc_state'); // draft, frozen, validated, released, superseded
            $table->string('manifest_hash'); // sha256 of manifest content
            $table->integer('subsystem_count')->default(0);
            $table->integer('rule_count')->default(0);
            $table->integer('hunt_domain_count')->default(0);
            $table->float('readiness_score')->default(0.0);
            $table->boolean('feature_freeze_enforced')->default(false);
            $table->boolean('soak_validated')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('autonomous_release')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('manifest_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: feature freeze audit
        Schema::create('feature_freeze_audit', function (Blueprint $table) {
            $table->id();
            $table->string('freeze_audit_id')->unique();
            $table->string('freeze_event'); // freeze_initiated, hotfix_approved, scope_change_rejected, freeze_lifted
            $table->string('freeze_scope'); // full, subsystem_specific, hotfix_only
            $table->string('subsystem')->nullable();
            $table->boolean('scope_expansion_blocked')->default(true);
            $table->boolean('hotfix_allowed')->default(false);
            $table->boolean('autonomous_scope_change')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->string('rc_version')->nullable();
            $table->json('freeze_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: deployment artifact reports
        Schema::create('deployment_artifact_reports', function (Blueprint $table) {
            $table->id();
            $table->string('artifact_report_id')->unique();
            $table->string('artifact_type'); // docker_compose, env_template, validation_bundle, startup_checklist, replay_bundle
            $table->string('artifact_state'); // generated, validated, signed, superseded
            $table->string('artifact_hash'); // sha256
            $table->integer('artifact_size_kb')->default(0);
            $table->boolean('integrity_verified')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('autonomous_generation')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->string('rc_version')->nullable();
            $table->json('artifact_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: pilot deployment preparation runs
        Schema::create('pilot_deployment_preparation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('pilot_prep_run_id')->unique();
            $table->string('preparation_scope'); // onboarding_readiness, deployment_check, continuity_verification, ha_checkpoint, rollback_readiness
            $table->string('preparation_state'); // pending, running, passed, failed, blocked
            $table->integer('pilot_endpoint_count')->default(0);
            $table->float('readiness_score')->default(0.0);
            $table->boolean('onboarding_ready')->default(false);
            $table->boolean('ha_ready')->default(false);
            $table->boolean('rollback_ready')->default(false);
            $table->boolean('telemetry_continuous')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('autonomous_rollout')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('preparation_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: operational validation baselines
        Schema::create('operational_validation_baselines', function (Blueprint $table) {
            $table->id();
            $table->string('baseline_id')->unique();
            $table->string('validation_domain'); // replay_continuity, queue_durability, telemetry_continuity, ha_continuity, deployment_reproducibility
            $table->string('validation_state'); // passing, degraded, failing, recovering
            $table->float('baseline_score')->default(0.0);
            $table->float('previous_score')->default(0.0);
            $table->float('drift_delta')->default(0.0);
            $table->boolean('within_bounds')->default(true);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('degradation_detected')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('baseline_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: release blocker reports
        Schema::create('release_blocker_reports', function (Blueprint $table) {
            $table->id();
            $table->string('blocker_report_id')->unique();
            $table->string('blocker_category'); // validation_failure, soak_incomplete, gate_failed, freeze_violated, artifact_corrupt, reproducibility_failure
            $table->string('blocker_severity'); // low, medium, high, critical
            $table->text('blocker_description');
            $table->string('blocker_state'); // open, mitigated, resolved, waived
            $table->boolean('release_blocked')->default(false);
            $table->boolean('autonomous_resolution')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->string('rc_version')->nullable();
            $table->json('blocker_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: deployment reproducibility reports
        Schema::create('deployment_reproducibility_reports', function (Blueprint $table) {
            $table->id();
            $table->string('reproducibility_report_id')->unique();
            $table->string('deployment_scope'); // full_stack, partial, canary, single_service
            $table->float('reproducibility_score')->default(0.0);
            $table->boolean('environment_matched')->default(false);
            $table->boolean('artifact_hash_verified')->default(false);
            $table->boolean('configuration_deterministic')->default(false);
            $table->boolean('replay_continuity_verified')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->boolean('autonomous_deployment')->default(false);
            $table->string('rc_version')->nullable();
            $table->json('reproducibility_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: stabilization validation runs
        Schema::create('stabilization_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('stabilization_run_id')->unique();
            $table->string('validation_type'); // pre_freeze, post_freeze, pre_rc, post_rc, pilot_prep
            $table->string('validation_state'); // pending, running, passed, failed
            $table->float('validation_score')->default(0.0);
            $table->boolean('tests_green')->default(false);
            $table->boolean('registry_valid')->default(false);
            $table->boolean('contracts_valid')->default(false);
            $table->boolean('resilience_valid')->default(false);
            $table->boolean('fleet_sim_valid')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->boolean('replay_safe')->default(true);
            $table->json('stabilization_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: release candidate audit
        Schema::create('release_candidate_audit', function (Blueprint $table) {
            $table->id();
            $table->string('rc_audit_id')->unique();
            $table->string('audit_event_type'); // rc_initiated, freeze_enforced, artifact_generated, blocker_raised, validation_passed, rc_released, rc_superseded
            $table->string('actor_type'); // operator, analyst, system
            $table->string('rc_version')->nullable();
            $table->boolean('autonomous_action_taken')->default(false);
            $table->boolean('destructive_action_taken')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->json('audit_evidence')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_candidate_audit');
        Schema::dropIfExists('stabilization_validation_runs');
        Schema::dropIfExists('deployment_reproducibility_reports');
        Schema::dropIfExists('release_blocker_reports');
        Schema::dropIfExists('operational_validation_baselines');
        Schema::dropIfExists('pilot_deployment_preparation_runs');
        Schema::dropIfExists('deployment_artifact_reports');
        Schema::dropIfExists('feature_freeze_audit');
        Schema::dropIfExists('release_candidate_manifests');
    }
};
