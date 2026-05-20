<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mutable governed playbooks
        Schema::create('soar_playbooks', function (Blueprint $table) {
            $table->id();
            $table->string('playbook_id', 40)->unique()->index();
            $table->string('name', 255);
            $table->text('description')->nullable();
            // status: draft, active, deprecated
            $table->string('status', 40)->default('draft')->index();
            $table->string('version', 20)->default('1.0.0');
            $table->string('execution_scope', 80)->default('advisory');
            $table->jsonb('allowed_actions')->nullable();
            $table->jsonb('approval_requirements')->nullable();
            $table->string('rollback_reference', 40)->nullable();
            $table->boolean('simulation_required')->default(true);
            $table->boolean('dual_approval_required')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        // Append-only immutable playbook version snapshots
        Schema::create('soar_playbook_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version_id', 40)->unique()->index();
            $table->string('playbook_id', 40)->index();
            $table->string('version', 20);
            $table->string('version_hash', 64)->index();
            $table->jsonb('playbook_snapshot');
            $table->text('change_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Mutable execution plans (multi-step orchestration)
        Schema::create('soar_execution_plans', function (Blueprint $table) {
            $table->id();
            $table->string('plan_id', 40)->unique()->index();
            $table->string('playbook_id', 40)->nullable()->index();
            $table->string('playbook_version_id', 40)->nullable()->index();
            $table->string('name', 255);
            // status: draft, simulation_pending, simulated, approval_pending, approved, executing, completed, rolled_back, cancelled, failed
            $table->string('status', 40)->default('draft')->index();
            $table->string('target_investigation_id', 40)->nullable()->index();
            $table->string('target_entity_id', 120)->nullable()->index();
            $table->float('blast_radius_score')->default(0.0);
            $table->boolean('simulation_required')->default(true);
            $table->boolean('simulation_completed')->default(false);
            $table->boolean('dual_approval_required')->default(true);
            $table->boolean('rollback_ready')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('approver_1_id')->nullable();
            $table->foreign('approver_1_id')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('approver_2_id')->nullable();
            $table->foreign('approver_2_id')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        // Mutable individual steps within a plan
        Schema::create('soar_execution_steps', function (Blueprint $table) {
            $table->id();
            $table->string('step_id', 40)->unique()->index();
            $table->string('plan_id', 40)->index();
            $table->integer('step_order');
            $table->string('step_name', 255);
            // action_type: notify_analyst, create_incident, create_ticket, enrich_ioc,
            //   request_approval, create_watchlist_entry, simulate_endpoint_isolation,
            //   simulate_credential_revocation, simulate_firewall_block, simulate_token_revocation
            $table->string('action_type', 80);
            // status: pending, simulation_preview, approved, completed, skipped, failed
            $table->string('status', 40)->default('pending')->index();
            $table->boolean('is_simulation_only')->default(true);
            $table->jsonb('action_parameters')->nullable();
            $table->jsonb('result_data')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Append-only execution results
        Schema::create('soar_execution_results', function (Blueprint $table) {
            $table->id();
            $table->string('result_id', 40)->unique()->index();
            $table->string('plan_id', 40)->index();
            $table->string('step_id', 40)->nullable()->index();
            $table->string('result_type', 80)->index();
            $table->boolean('success');
            $table->text('result_summary')->nullable();
            $table->jsonb('result_data')->nullable();
            $table->boolean('is_simulation')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->string('trace_id', 120)->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Append-only approval request trail
        Schema::create('soar_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('approval_id', 40)->unique()->index();
            $table->string('plan_id', 40)->index();
            $table->string('playbook_id', 40)->nullable()->index();
            // approval_type: initial, secondary, escalation
            $table->string('approval_type', 40)->default('initial');
            // decision: pending, approved, rejected
            $table->string('decision', 40)->default('pending')->index();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
            $table->text('rationale')->nullable();
            $table->jsonb('gate_snapshot')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Mutable rollback plans
        Schema::create('soar_rollback_plans', function (Blueprint $table) {
            $table->id();
            $table->string('rollback_id', 40)->unique()->index();
            $table->string('plan_id', 40)->index();
            $table->jsonb('rollback_steps')->nullable();
            $table->string('rollback_scope', 80)->default('advisory');
            // rollback_readiness_state: not_ready, ready, validated, executed
            $table->string('rollback_readiness_state', 40)->default('not_ready')->index();
            $table->jsonb('rollback_dependencies')->nullable();
            $table->boolean('simulation_validated')->default(false);
            $table->boolean('approval_required')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        // Append-only full audit trail
        Schema::create('soar_execution_audit', function (Blueprint $table) {
            $table->id();
            $table->string('audit_id', 40)->unique()->index();
            $table->string('plan_id', 40)->nullable()->index();
            $table->string('playbook_id', 40)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Append-only simulation artifacts
        Schema::create('soar_simulation_results', function (Blueprint $table) {
            $table->id();
            $table->string('sim_result_id', 40)->unique()->index();
            $table->string('plan_id', 40)->index();
            $table->string('playbook_id', 40)->nullable()->index();
            $table->float('blast_radius_score')->default(0.0);
            $table->integer('estimated_affected_entities')->default(0);
            $table->jsonb('affected_entity_ids')->nullable();
            $table->jsonb('step_previews')->nullable();
            $table->boolean('rollback_ready')->default(false);
            $table->boolean('approval_required')->default(true);
            $table->jsonb('warnings')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->unsignedBigInteger('simulated_by')->nullable();
            $table->foreign('simulated_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soar_simulation_results');
        Schema::dropIfExists('soar_execution_audit');
        Schema::dropIfExists('soar_rollback_plans');
        Schema::dropIfExists('soar_approval_requests');
        Schema::dropIfExists('soar_execution_results');
        Schema::dropIfExists('soar_execution_steps');
        Schema::dropIfExists('soar_execution_plans');
        Schema::dropIfExists('soar_playbook_versions');
        Schema::dropIfExists('soar_playbooks');
    }
};
