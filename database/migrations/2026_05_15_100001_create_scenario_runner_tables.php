<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenario_runs', function (Blueprint $table) {
            $table->id();
            $table->string('scenario_id', 100)->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('pending'); // pending|running|completed|failed|stopped
            $table->string('run_mode', 20)->default('live'); // live|replay
            $table->string('trace_id', 120)->nullable()->index();
            $table->json('config')->nullable();
            $table->json('results')->nullable();
            $table->integer('alerts_detected')->default(0);
            $table->boolean('detection_passed')->nullable();
            $table->string('validation_result', 20)->nullable(); // PASS|FAIL|PARTIAL
            $table->text('failure_reason')->nullable();
            $table->text('recommendation')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['scenario_id', 'status']);
            $table->index('created_at');
        });

        Schema::create('scenario_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenario_run_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 60)->index(); // ingestion|telemetry.raw|normalizer|telemetry.normalized|correlation|xdr.alerts|alerts.created|incidents.updated
            $table->string('event_id', 120)->nullable()->index();
            $table->string('trace_id', 120)->nullable()->index();
            $table->string('rule_id', 120)->nullable();
            $table->string('severity', 20)->nullable();
            $table->string('status', 30)->default('pending'); // pending|processing|detected|failed
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['scenario_run_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_evidence');
        Schema::dropIfExists('scenario_runs');
    }
};
