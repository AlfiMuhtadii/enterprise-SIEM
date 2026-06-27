<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENTERPRISE-060: Real Domain Soak Execution Plan
     *
     * Five append-only tables tracking the phased soak execution plan.
     * Promotion remains BLOCKED until each phase's real soak PASS.
     * NEVER UPDATE or DELETE rows in any of these tables.
     */
    public function up(): void
    {
        Schema::create('soak_plan_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('plan_run_id')->unique()->index();
            $table->unsignedTinyInteger('phases_total')->default(4);
            $table->unsignedTinyInteger('phases_ready')->default(0);
            $table->unsignedTinyInteger('phases_partial')->default(0);
            $table->unsignedTinyInteger('phases_blocked')->default(0);
            $table->unsignedSmallInteger('total_gates')->default(0);
            $table->unsignedSmallInteger('gates_passed')->default(0);
            $table->string('overall_readiness', 32)->nullable();
            $table->boolean('real_execution_gated')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('soak_plan_phases', function (Blueprint $table) {
            $table->id();
            $table->uuid('plan_run_id')->index();
            $table->unsignedTinyInteger('phase_number');
            $table->string('phase_name', 200);
            $table->string('rule_scope', 200);
            $table->unsignedSmallInteger('rule_count')->default(0);
            $table->string('readiness_status', 16)->default('PARTIAL'); // READY|PARTIAL|BLOCKED
            $table->unsignedTinyInteger('gates_passed')->default(0);
            $table->unsignedTinyInteger('gates_total')->default(0);
            $table->boolean('promotion_gated')->default(true);          // always true
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('recorded_at')->useCurrent();
            $table->index(['plan_run_id', 'phase_number']);
        });

        Schema::create('soak_plan_gates', function (Blueprint $table) {
            $table->id();
            $table->uuid('plan_run_id')->index();
            $table->unsignedTinyInteger('phase_number');
            $table->string('gate_id', 32);
            $table->string('gate_name', 200);
            $table->boolean('passed')->default(false);
            $table->string('status', 8)->default('warn');   // pass|warn|fail
            $table->text('evidence')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('checked_at')->useCurrent();
            $table->index(['plan_run_id', 'phase_number', 'gate_id']);
        });

        Schema::create('soak_plan_readiness_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('plan_run_id')->index();
            $table->unsignedTinyInteger('phase_number');
            $table->string('note_type', 32);
            $table->text('note_text');
            $table->boolean('is_advisory')->default(true);
            $table->timestampTz('recorded_at')->useCurrent();
            $table->index(['plan_run_id', 'phase_number']);
        });

        Schema::create('soak_plan_audit_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('plan_run_id')->index();
            $table->string('action', 64);
            $table->text('detail')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->timestampTz('recorded_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soak_plan_audit_events');
        Schema::dropIfExists('soak_plan_readiness_notes');
        Schema::dropIfExists('soak_plan_gates');
        Schema::dropIfExists('soak_plan_phases');
        Schema::dropIfExists('soak_plan_runs');
    }
};
