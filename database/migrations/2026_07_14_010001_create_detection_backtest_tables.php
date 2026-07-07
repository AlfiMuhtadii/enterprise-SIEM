<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mutable: one row per backtest invocation. Immutable in practice
        // (no updated_at) — a run is a point-in-time record of what was
        // tested and over what window.
        // CAP-DETECT-BACKTEST: replay-safe, advisory-only. Never writes to
        // security_alerts/security_incidents — a "would-have-fired" count
        // is reported here, not an active alert.
        Schema::create('detection_backtest_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->jsonb('rule_ids');
            $table->timestampTz('window_start');
            $table->timestampTz('window_end');
            $table->unsignedInteger('telemetry_event_count')->default(0);
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('triggered_by')->references('id')->on('users')->nullOnDelete();
        });

        // Append-only: one row per (rule_id, actor_key) that would have
        // fired within a run's window. Sample events are a small preview
        // for analyst review, not a replacement for the real telemetry.
        Schema::create('detection_backtest_matches', function (Blueprint $table) {
            $table->id();
            $table->string('match_id')->unique();
            $table->string('run_id');
            $table->string('rule_id', 120);
            $table->string('actor_key', 255)->nullable();
            $table->unsignedInteger('event_count')->default(0);
            $table->jsonb('sample_events')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('run_id')->references('run_id')->on('detection_backtest_runs');
            $table->index('rule_id');
            $table->index(['run_id', 'rule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detection_backtest_matches');
        Schema::dropIfExists('detection_backtest_runs');
    }
};
