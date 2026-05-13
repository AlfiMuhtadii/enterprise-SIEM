<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('containment_simulations', function (Blueprint $table) {
            $table->id();
            $table->string('simulation_id', 80)->unique();
            $table->string('response_id', 80)->nullable()->index();
            $table->string('target_type', 32)->index();
            $table->string('target_value', 160)->index();
            $table->string('action_type', 64)->index();
            $table->string('status', 32)->default('pending_approval')->index();
            $table->string('requested_by', 120)->default('system')->index();
            $table->string('approved_by', 120)->nullable()->index();
            $table->timestampTz('approved_at')->nullable();
            $table->jsonb('simulation_result')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['action_type', 'created_at']);
        });

        Schema::create('enterprise_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 80)->unique();
            $table->string('run_type', 64)->index();
            $table->string('environment', 32)->index();
            $table->string('status', 32)->default('completed')->index();
            $table->jsonb('metrics')->nullable();
            $table->jsonb('warnings')->nullable();
            $table->string('generated_by', 120)->default('system')->index();
            $table->timestampTz('started_at')->nullable()->index();
            $table->timestampTz('completed_at')->nullable()->index();
            $table->timestampsTz();
        });

        Schema::create('retention_cost_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id', 80)->unique();
            $table->string('environment', 32)->index();
            $table->unsignedInteger('period_days')->default(30);
            $table->jsonb('storage_metrics');
            $table->jsonb('estimated_cost');
            $table->jsonb('recommendations')->nullable();
            $table->string('generated_by', 120)->default('system')->index();
            $table->timestampsTz();
            $table->index(['environment', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_cost_reports');
        Schema::dropIfExists('enterprise_validation_runs');
        Schema::dropIfExists('containment_simulations');
    }
};
