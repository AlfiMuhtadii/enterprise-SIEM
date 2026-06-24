<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mutable — status evolves from initiated → passed/failed; DELETE forbidden
        Schema::create('pilot_readiness_matrix_runs', function (Blueprint $table) {
            $table->id();
            $table->string('matrix_run_id')->unique();
            $table->string('tenant_id');
            $table->string('operator_id');
            $table->json('pilot_scope')->nullable();
            $table->string('status', 20)->default('initiated');
            $table->unsignedSmallInteger('total_gates')->default(0);
            $table->unsignedSmallInteger('gates_passed')->default(0);
            $table->unsignedSmallInteger('gates_warned')->default(0);
            $table->unsignedSmallInteger('gates_failed')->default(0);
            $table->boolean('required_gates_pass')->default(false);
            $table->float('matrix_score')->default(0.0);
            $table->timestamp('finalized_at')->nullable();
            $table->string('approved_by')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->boolean('autonomous_promotion')->default(false);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('status');
        });

        // Append-only — gate evaluation results per dimension
        Schema::create('pilot_readiness_gate_evaluations', function (Blueprint $table) {
            $table->id();
            $table->string('matrix_run_id');
            $table->string('gate_id');
            $table->string('dimension', 30);
            $table->string('status', 10)->default('skip');
            $table->text('detail')->nullable();
            $table->float('score')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->index('matrix_run_id');
            $table->index(['matrix_run_id', 'dimension']);
            $table->index(['matrix_run_id', 'gate_id']);
        });

        // Append-only — evidence links from matrix gates to source service records
        Schema::create('pilot_readiness_evidence_links', function (Blueprint $table) {
            $table->id();
            $table->string('matrix_run_id');
            $table->unsignedBigInteger('gate_eval_id');
            $table->string('source_type');
            $table->string('source_id');
            $table->json('evidence_data')->nullable();
            $table->timestamp('linked_at');
            $table->timestamps();

            $table->index('matrix_run_id');
            $table->index('gate_eval_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pilot_readiness_evidence_links');
        Schema::dropIfExists('pilot_readiness_gate_evaluations');
        Schema::dropIfExists('pilot_readiness_matrix_runs');
    }
};
