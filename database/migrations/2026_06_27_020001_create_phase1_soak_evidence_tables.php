<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENTERPRISE-061: Real Soak Phase 1 Evidence
     *
     * Four append-only tables for pre-soak gate evidence and metrics.
     * NO_PROMOTION = true — these tables record evidence only; no run
     * from this framework authorizes rule promotion.
     * NEVER UPDATE or DELETE rows in any of these tables.
     */
    public function up(): void
    {
        Schema::create('phase1_soak_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('soak_run_id')->unique()->index();
            $table->string('scope', 64)->default('staged_active_empirical');
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->unsignedTinyInteger('rules_in_scope')->default(0);
            $table->unsignedTinyInteger('gates_total')->default(0);
            $table->unsignedTinyInteger('gates_passed')->default(0);
            $table->unsignedTinyInteger('gates_warned')->default(0);
            $table->unsignedTinyInteger('gates_failed')->default(0);
            $table->string('decision', 8)->default('WARN');   // PASS|WARN|FAIL
            $table->boolean('no_promotion')->default(true);   // ALWAYS true
            $table->boolean('is_advisory')->default(true);
            $table->boolean('is_dry_run')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('started_at')->useCurrent();
        });

        Schema::create('phase1_soak_gate_results', function (Blueprint $table) {
            $table->id();
            $table->uuid('soak_run_id')->index();
            $table->string('gate_id', 16);                    // P1G-01..P1G-08
            $table->string('gate_name', 200);
            $table->string('status', 8)->default('warn');     // pass|warn|fail
            $table->boolean('passed')->default(false);
            $table->text('evidence')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('checked_at')->useCurrent();
            $table->index(['soak_run_id', 'gate_id']);
        });

        Schema::create('phase1_soak_metrics', function (Blueprint $table) {
            $table->id();
            $table->uuid('soak_run_id')->index();
            $table->string('metric_key', 64);
            $table->string('metric_value', 128);
            $table->string('unit', 32)->nullable();
            $table->boolean('within_bounds')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->timestampTz('recorded_at')->useCurrent();
        });

        Schema::create('phase1_soak_audit_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('soak_run_id')->index();
            $table->string('action', 64);
            $table->text('detail')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->timestampTz('recorded_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phase1_soak_audit_events');
        Schema::dropIfExists('phase1_soak_metrics');
        Schema::dropIfExists('phase1_soak_gate_results');
        Schema::dropIfExists('phase1_soak_runs');
    }
};
