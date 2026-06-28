<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only audit of each backfill command execution
        Schema::create('tenant_backfill_audit_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->string('initiated_by')->nullable();
            $table->boolean('dry_run')->default(true);
            $table->string('tenant_id_assigned')->nullable();
            $table->integer('batch_size')->default(1000);
            $table->json('table_results');        // per-table null_before/updated/null_after/status
            $table->integer('total_null_before')->default(0);
            $table->integer('total_updated')->default(0);
            $table->integer('total_null_after')->default(0);
            $table->string('outcome');            // CLEAN | DONE | PARTIAL | DRY_RUN_PENDING
            $table->timestamps();
        });

        // Append-only readiness assessments
        Schema::create('tenant_strict_mode_assessments', function (Blueprint $table) {
            $table->id();
            $table->string('assessment_id')->unique();
            $table->string('assessed_by')->nullable();
            $table->integer('gates_total')->default(0);
            $table->integer('gates_passed')->default(0);
            $table->integer('gates_warned')->default(0);
            $table->integer('gates_failed')->default(0);
            $table->float('readiness_score')->default(0.0);
            $table->string('overall_status');     // READY | NOT_READY | WARN
            $table->boolean('strict_mode_recommended')->default(false);
            $table->json('summary');
            $table->timestamps();
        });

        // Append-only individual gate results per assessment
        Schema::create('tenant_strict_mode_gate_results', function (Blueprint $table) {
            $table->id();
            $table->string('assessment_id');
            $table->string('gate_id');            // GATE-01 … GATE-07
            $table->string('gate_name');
            $table->string('result');             // PASS | WARN | FAIL
            $table->text('detail')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('assessment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_strict_mode_gate_results');
        Schema::dropIfExists('tenant_strict_mode_assessments');
        Schema::dropIfExists('tenant_backfill_audit_runs');
    }
};
