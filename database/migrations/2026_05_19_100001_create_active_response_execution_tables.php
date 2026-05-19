<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Current execution state — mutable, tracks approval + lifecycle
        Schema::create('response_executions', function (Blueprint $table) {
            $table->id();
            $table->string('execution_id', 40)->unique()->index();         // EXEC-YYYY-NNNNN
            $table->string('action_type', 40)->index();                    // revoke_session|disable_user|isolate_host|block_ip|block_domain
            $table->string('target_entity_type', 30)->index();
            $table->string('target_entity_key', 255)->index();
            $table->unsignedBigInteger('target_entity_id')->nullable();
            $table->foreign('target_entity_id')->references('id')->on('entities')->nullOnDelete();
            $table->unsignedBigInteger('created_by');
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->string('status', 30)->default('draft')->index();       // 11-state machine
            $table->boolean('requires_dual_approval')->default(false);
            // First approver
            $table->unsignedBigInteger('approver_1_id')->nullable();
            $table->foreign('approver_1_id')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('approver_1_approved_at')->nullable();
            $table->text('approver_1_rationale')->nullable();
            // Second approver (dual-approval actions only)
            $table->unsignedBigInteger('approver_2_id')->nullable();
            $table->foreign('approver_2_id')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('approver_2_approved_at')->nullable();
            $table->text('approver_2_rationale')->nullable();
            $table->timestamp('approval_expires_at')->nullable()->index();
            // Simulation
            $table->boolean('simulation_required')->default(true);
            $table->timestamp('simulation_completed_at')->nullable();
            // Execution window
            $table->timestamp('execution_started_at')->nullable();
            $table->timestamp('execution_completed_at')->nullable();
            $table->unsignedInteger('execution_timeout_seconds')->default(300);
            // Rollback
            $table->boolean('rollback_supported')->default(false);
            $table->timestamp('rollback_deadline_at')->nullable();
            // Scoring — deterministic, advisory
            $table->decimal('blast_radius_score', 4, 2)->default(0.00);
            $table->decimal('execution_safety_score', 4, 2)->default(0.00);
            $table->decimal('execution_confidence_score', 4, 2)->default(0.00);
            // Evidence links
            $table->jsonb('hunt_evidence')->default('[]');
            $table->jsonb('correlation_ids')->default('[]');
            // Free text
            $table->text('rationale');
            $table->text('notes')->nullable();
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamps();
        });

        // Audit trail — every state transition + approval, append-only
        Schema::create('response_execution_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('execution_id');
            $table->foreign('execution_id')->references('id')->on('response_executions')->cascadeOnDelete();
            $table->string('event_type', 60)->index();
            $table->string('from_state', 30)->nullable();
            $table->string('to_state', 30)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->string('actor_name', 120)->nullable();
            $table->jsonb('details')->default('{}');
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamp('created_at');                               // no updated_at — append-only
        });

        // Rollback records — append-only
        Schema::create('response_execution_rollbacks', function (Blueprint $table) {
            $table->id();
            $table->string('rollback_id', 40)->unique()->index();          // ROLL-YYYY-NNNNN
            $table->unsignedBigInteger('execution_id');
            $table->foreign('execution_id')->references('id')->on('response_executions')->cascadeOnDelete();
            $table->string('rollback_type', 30)->default('manual');        // manual|timeout|auto_failed
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->foreign('initiated_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('initiated_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 30)->default('initiated');            // initiated|completed|failed
            $table->jsonb('rollback_evidence')->default('{}');
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamp('created_at');                               // no updated_at — append-only
        });

        // Simulation outputs — append-only
        Schema::create('response_execution_simulations', function (Blueprint $table) {
            $table->id();
            $table->string('simulation_id', 40)->unique()->index();        // SIM-YYYY-NNNNN
            $table->unsignedBigInteger('execution_id');
            $table->foreign('execution_id')->references('id')->on('response_executions')->cascadeOnDelete();
            $table->unsignedBigInteger('simulated_by')->nullable();
            $table->foreign('simulated_by')->references('id')->on('users')->nullOnDelete();
            $table->jsonb('blast_radius_entities')->default('[]');
            $table->jsonb('impacted_services')->default('[]');
            $table->boolean('rollback_available')->default(false);
            $table->decimal('estimated_impact_score', 4, 2)->default(0.00);
            $table->text('simulation_notes')->nullable();
            $table->jsonb('warnings')->default('[]');
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamp('created_at');                               // no updated_at — append-only
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('response_execution_simulations');
        Schema::dropIfExists('response_execution_rollbacks');
        Schema::dropIfExists('response_execution_events');
        Schema::dropIfExists('response_executions');
    }
};
