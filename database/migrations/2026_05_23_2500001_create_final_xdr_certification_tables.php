<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: XDR readiness certifications
        Schema::create('xdr_readiness_certifications', function (Blueprint $table) {
            $table->id();
            $table->string('certification_id')->unique();
            $table->string('certification_type'); // initial, renewal, post_incident, domain_specific
            $table->string('certification_state'); // pending, in_progress, passed, failed, expired
            $table->float('readiness_score')->default(0.0);
            $table->float('gate_pass_rate')->default(0.0);
            $table->boolean('all_gates_passed')->default(false);
            $table->boolean('soak_validated')->default(false);
            $table->boolean('replay_verified')->default(false);
            $table->boolean('advisory_only')->default(true);
            $table->boolean('autonomous_approval')->default(false);
            $table->json('certification_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: production acceptance gates
        Schema::create('production_acceptance_gates', function (Blueprint $table) {
            $table->id();
            $table->string('gate_run_id')->unique();
            $table->string('gate_name'); // soak_validation, replay_integrity, contract_compliance, rule_registry, fleet_simulation
            $table->string('gate_state'); // pending, running, passed, failed, waived
            $table->float('gate_score')->default(0.0);
            $table->boolean('gate_passed')->default(false);
            $table->boolean('self_approve_blocked')->default(true);
            $table->boolean('autonomous_waiver')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->string('certification_id')->nullable();
            $table->json('gate_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: operational limitation reports
        Schema::create('operational_limitation_reports', function (Blueprint $table) {
            $table->id();
            $table->string('limitation_report_id')->unique();
            $table->string('limitation_category'); // advisory_only, shadow_scope, soak_required, capacity_bound, feature_bound
            $table->string('limitation_severity'); // info, low, medium, high
            $table->text('limitation_description');
            $table->string('affected_domain')->nullable();
            $table->boolean('cutover_blocked')->default(false);
            $table->boolean('mitigation_available')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('limitation_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: executive readiness reports
        Schema::create('executive_readiness_reports', function (Blueprint $table) {
            $table->id();
            $table->string('exec_report_id')->unique();
            $table->string('report_scope'); // identity_cloud, endpoint_shadow, network_shadow, full_platform
            $table->float('overall_readiness_score')->default(0.0);
            $table->integer('gates_passed')->default(0);
            $table->integer('gates_total')->default(0);
            $table->integer('limitations_open')->default(0);
            $table->integer('risks_high')->default(0);
            $table->string('go_live_recommendation'); // approved, conditional, deferred, blocked
            $table->boolean('autonomous_approval')->default(false);
            $table->boolean('self_approve_blocked')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('exec_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: technical readiness reports
        Schema::create('technical_readiness_reports', function (Blueprint $table) {
            $table->id();
            $table->string('tech_report_id')->unique();
            $table->string('report_domain'); // correlation_engine, endpoint_agent, streaming_telemetry, network_analytics, soar_governance
            $table->float('domain_readiness_score')->default(0.0);
            $table->boolean('soak_passed')->default(false);
            $table->boolean('replay_integrity_verified')->default(false);
            $table->boolean('contract_compliant')->default(false);
            $table->boolean('rule_registry_valid')->default(false);
            $table->boolean('fleet_simulation_passed')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('tech_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: production risk register
        Schema::create('production_risk_register', function (Blueprint $table) {
            $table->id();
            $table->string('risk_id')->unique();
            $table->string('risk_category'); // replay_failure, contract_drift, cutover_premature, soak_skipped, capacity_overrun
            $table->string('risk_severity'); // low, medium, high, critical
            $table->float('risk_score')->default(0.0);
            $table->text('risk_description');
            $table->string('risk_state'); // open, mitigated, accepted, resolved
            $table->boolean('cutover_blocker')->default(false);
            $table->boolean('autonomous_mitigation')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('risk_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: go-live validation runs
        Schema::create('go_live_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('go_live_run_id')->unique();
            $table->string('validation_scope'); // pre_cutover, post_cutover, rollback_verification, domain_specific
            $table->string('validation_state'); // pending, running, passed, failed, aborted
            $table->float('validation_score')->default(0.0);
            $table->boolean('soak_requirement_met')->default(false);
            $table->boolean('gate_requirement_met')->default(false);
            $table->boolean('risk_acceptable')->default(false);
            $table->boolean('rollback_ready')->default(false);
            $table->boolean('autonomous_go_live')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->string('certification_id')->nullable();
            $table->json('validation_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: deployment acceptance audit
        Schema::create('deployment_acceptance_audit', function (Blueprint $table) {
            $table->id();
            $table->string('acceptance_audit_id')->unique();
            $table->string('audit_event_type'); // gate_evaluated, risk_registered, limitation_recorded, report_generated, certification_issued, go_live_validated
            $table->string('actor_type'); // operator, analyst, system
            $table->string('target_entity')->nullable();
            $table->boolean('self_approve_blocked')->default(true);
            $table->boolean('autonomous_action_taken')->default(false);
            $table->boolean('destructive_action_taken')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->json('audit_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: final XDR certification audit
        Schema::create('final_xdr_certification_audit', function (Blueprint $table) {
            $table->id();
            $table->string('cert_audit_id')->unique();
            $table->string('audit_event_type'); // certification_initiated, gate_passed, gate_failed, risk_escalated, report_signed, go_live_approved, rollback_triggered
            $table->string('actor_type'); // operator, analyst, system
            $table->string('certification_id')->nullable();
            $table->boolean('autonomous_action_taken')->default(false);
            $table->boolean('destructive_action_taken')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->json('audit_evidence')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_xdr_certification_audit');
        Schema::dropIfExists('deployment_acceptance_audit');
        Schema::dropIfExists('go_live_validation_runs');
        Schema::dropIfExists('production_risk_register');
        Schema::dropIfExists('technical_readiness_reports');
        Schema::dropIfExists('executive_readiness_reports');
        Schema::dropIfExists('operational_limitation_reports');
        Schema::dropIfExists('production_acceptance_gates');
        Schema::dropIfExists('xdr_readiness_certifications');
    }
};
