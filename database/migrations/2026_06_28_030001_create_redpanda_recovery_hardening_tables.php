<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only topic health snapshots
        Schema::create('redpanda_topic_health_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->string('triggered_by')->nullable();
            $table->integer('topics_expected')->default(0);
            $table->integer('topics_found')->default(0);
            $table->integer('topics_missing')->default(0);
            $table->json('topic_status');         // per-topic: exists, watermark_low, watermark_high
            $table->string('overall_status');     // PASS | WARN | FAIL
            $table->boolean('bootstrap_needed')->default(false);
            $table->timestamps();
        });

        // Append-only consumer group health runs
        Schema::create('redpanda_consumer_group_health_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->string('triggered_by')->nullable();
            $table->integer('groups_checked')->default(0);
            $table->integer('groups_healthy')->default(0);
            $table->integer('groups_lagging')->default(0);
            $table->integer('groups_unknown')->default(0);
            $table->json('group_status');         // per-group: lag, status, last_commit
            $table->string('overall_status');     // PASS | WARN | FAIL
            $table->timestamps();
        });

        // Append-only recovery audit events
        Schema::create('redpanda_recovery_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('event_type');         // OFFSET_RESET | CONSUMER_RESTART | TOPIC_RECREATE | BOOTSTRAP
            $table->string('affected_topic')->nullable();
            $table->string('affected_group')->nullable();
            $table->string('triggered_by')->nullable();
            $table->string('outcome');            // SUCCESS | FAILED | ADVISORY
            $table->text('detail')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redpanda_recovery_events');
        Schema::dropIfExists('redpanda_consumer_group_health_runs');
        Schema::dropIfExists('redpanda_topic_health_runs');
    }
};
