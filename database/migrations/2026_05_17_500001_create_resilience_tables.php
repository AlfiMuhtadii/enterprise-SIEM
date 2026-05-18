<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resilience_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 40)->unique()->index();
            $table->string('scenario_id', 80)->index();
            $table->string('scenario_name', 255);
            $table->string('scenario_type', 30)->default('simulation'); // active|simulation
            $table->string('status', 30)->index();                       // pending|running|passed|failed|error
            $table->boolean('recovery_validated')->default(false);
            $table->boolean('replay_safe')->default(false);
            $table->boolean('trace_preserved')->default(false);
            $table->boolean('endpoint_shadow_isolated')->default(true);
            $table->boolean('no_duplicates')->default(true);
            $table->jsonb('findings')->nullable();
            $table->string('report_path', 500)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('resilience_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('resilience_runs')->cascadeOnDelete();
            $table->string('metric_name', 80);
            $table->decimal('metric_value', 12, 4);
            $table->string('unit', 30)->default('count');
            $table->decimal('threshold', 12, 4)->nullable();
            $table->boolean('within_threshold')->default(true);
            $table->timestamp('recorded_at');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resilience_metrics');
        Schema::dropIfExists('resilience_runs');
    }
};
