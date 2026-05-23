<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: tenant onboarding runs
        Schema::create('tenant_onboarding_runs', function (Blueprint $table) {
            $table->id();
            $table->string('onboarding_run_id')->unique();
            $table->string('tenant_id');
            $table->string('onboarding_phase'); // environment_validation, schema_provisioning, policy_install, integration_check, activation
            $table->string('onboarding_state'); // initiated, validating, checkpoint_passed, completed, failed, rolled_back
            $table->integer('endpoint_count')->default(0);
            $table->float('readiness_score')->default(0.0);
            $table->boolean('validation_passed')->default(false);
            $table->boolean('rollback_capable')->default(true);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('onboarding_evidence')->nullable();
            $table->timestamps();
        });

        // Mutable: deployment package profiles
        Schema::create('deployment_package_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('package_profile_id')->unique();
            $table->string('profile_name');
            $table->string('environment'); // local, staging, production
            $table->string('deployment_tier'); // minimal, standard, enterprise
            $table->string('package_version');
            $table->string('checksum')->nullable();
            $table->boolean('checksum_verified')->default(false);
            $table->boolean('compatibility_verified')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('infrastructure_requirements')->nullable();
            $table->json('package_manifest')->nullable();
            $table->timestamps();
        });

        // Append-only: commercial release history
        Schema::create('commercial_release_history', function (Blueprint $table) {
            $table->id();
            $table->string('release_id')->unique();
            $table->string('release_version');
            $table->string('release_stage'); // alpha, beta, rc, ga, lts
            $table->string('release_state'); // drafted, validated, approved, published, deprecated
            $table->string('manifest_hash');
            $table->boolean('migration_compatible')->default(true);
            $table->boolean('rollback_compatible')->default(true);
            $table->boolean('self_approve_blocked')->default(true);
            $table->boolean('replay_safe')->default(true);
            $table->json('release_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: support bundle exports
        Schema::create('support_bundle_exports', function (Blueprint $table) {
            $table->id();
            $table->string('bundle_export_id')->unique();
            $table->string('bundle_type'); // diagnostics, deployment, queue, storage, environment, audit
            $table->string('export_scope'); // single_tenant, environment, infrastructure
            $table->float('export_size_mb')->default(0.0);
            $table->boolean('size_bounded')->default(true);
            $table->boolean('destructive_action_taken')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('bundle_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: deployment readiness reports
        Schema::create('deployment_readiness_reports', function (Blueprint $table) {
            $table->id();
            $table->string('readiness_report_id')->unique();
            $table->string('tenant_id');
            $table->string('environment');
            $table->float('readiness_score')->default(0.0);
            $table->boolean('onboarding_complete')->default(false);
            $table->boolean('telemetry_healthy')->default(false);
            $table->boolean('environment_valid')->default(false);
            $table->boolean('deployment_ready')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('readiness_evidence')->nullable();
            $table->timestamps();
        });

        // Mutable: customer operations health
        Schema::create('customer_operations_health', function (Blueprint $table) {
            $table->id();
            $table->string('health_record_id')->unique();
            $table->string('tenant_id');
            $table->float('health_score')->default(0.0);
            $table->boolean('deployment_healthy')->default(false);
            $table->boolean('telemetry_continuous')->default(false);
            $table->boolean('support_ready')->default(false);
            $table->boolean('onboarding_complete')->default(false);
            $table->string('health_state'); // healthy, degraded, at_risk, critical
            $table->json('health_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: onboarding checkpoint history
        Schema::create('onboarding_checkpoint_history', function (Blueprint $table) {
            $table->id();
            $table->string('onboarding_checkpoint_id')->unique();
            $table->string('onboarding_run_id');
            $table->string('checkpoint_type'); // pre_onboarding, environment_validated, schema_provisioned, policy_installed, integration_verified, activation_complete
            $table->boolean('passed')->default(false);
            $table->boolean('rollback_triggered')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('checkpoint_details')->nullable();
            $table->timestamps();
        });

        // Append-only: release compatibility reports
        Schema::create('release_compatibility_reports', function (Blueprint $table) {
            $table->id();
            $table->string('compatibility_report_id')->unique();
            $table->string('release_id');
            $table->string('from_version');
            $table->string('to_version');
            $table->boolean('schema_compatible')->default(true);
            $table->boolean('migration_safe')->default(true);
            $table->boolean('rollback_safe')->default(true);
            $table->boolean('upgrade_advisory_issued')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->json('compatibility_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: commercial product audit
        Schema::create('commercial_product_audit', function (Blueprint $table) {
            $table->id();
            $table->string('commercial_audit_id')->unique();
            $table->string('audit_event_type'); // onboarding_initiated, package_validated, release_published, support_exported, readiness_assessed, health_updated, compatibility_checked
            $table->string('actor_type'); // operator, system, analyst
            $table->string('target_entity')->nullable();
            $table->boolean('autonomous_action_taken')->default(false);
            $table->boolean('destructive_action_taken')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->json('audit_evidence')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_product_audit');
        Schema::dropIfExists('release_compatibility_reports');
        Schema::dropIfExists('onboarding_checkpoint_history');
        Schema::dropIfExists('customer_operations_health');
        Schema::dropIfExists('deployment_readiness_reports');
        Schema::dropIfExists('support_bundle_exports');
        Schema::dropIfExists('commercial_release_history');
        Schema::dropIfExists('deployment_package_profiles');
        Schema::dropIfExists('tenant_onboarding_runs');
    }
};
