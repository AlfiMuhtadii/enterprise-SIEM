<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENTERPRISE-048: Endpoint Shadow Domain Soak Plan
     *
     * Three append-only tables. NEVER UPDATE or DELETE rows.
     * plan_approved is ALWAYS false — actual soak execution requires
     * human sign-off + 6h soak harness PASS + ACTIVE_ALLOWLIST update.
     */
    public function up(): void
    {
        // Summary record per plan generation run
        Schema::create('endpoint_soak_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('plan_run_id')->unique()->index();
            $table->string('domain', 64)->default('endpoint');
            $table->unsignedInteger('total_rules')->default(0);
            $table->unsignedInteger('tier_1_count')->default(0);
            $table->unsignedInteger('tier_2_count')->default(0);
            $table->unsignedInteger('tier_3_count')->default(0);
            $table->float('tier_1_threshold')->default(0.72);
            $table->float('tier_2_threshold')->default(0.60);
            $table->boolean('plan_approved')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('generated_at')->useCurrent();
            $table->index('domain');
        });

        // Per-rule tier assignment within a plan run
        Schema::create('endpoint_soak_plan_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('plan_run_id')->index();
            $table->string('rule_id', 128);
            $table->string('domain', 64)->default('endpoint');
            $table->string('tier', 32);              // tier_1_soak_ready | tier_2_evidence_collection | tier_3_needs_tuning
            $table->float('confidence')->nullable();
            $table->string('false_positive_risk', 16)->default('unknown');
            $table->text('soak_rationale')->nullable();
            $table->unsignedInteger('estimated_soak_window')->nullable(); // window number (1, 2, 3)
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('evaluated_at')->useCurrent();
            $table->index(['plan_run_id', 'tier']);
            $table->index(['rule_id', 'evaluated_at']);
        });

        // Prerequisite gate checks for the endpoint soak plan
        Schema::create('endpoint_soak_plan_gates', function (Blueprint $table) {
            $table->id();
            $table->uuid('plan_run_id')->index();
            $table->string('gate_id', 32);           // GATE-01 through GATE-05
            $table->string('gate_name', 128);
            $table->boolean('passed')->default(false);
            $table->string('status', 16)->default('pending'); // pass | warn | fail | pending
            $table->text('detail')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('checked_at')->useCurrent();
            $table->index(['plan_run_id', 'gate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_soak_plan_gates');
        Schema::dropIfExists('endpoint_soak_plan_rules');
        Schema::dropIfExists('endpoint_soak_plans');
    }
};
