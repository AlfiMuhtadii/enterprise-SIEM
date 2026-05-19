<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Streamed endpoint telemetry events — append-only
        Schema::create('endpoint_stream_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 48)->unique()->index();          // UUID-based dedup key
            $table->string('agent_id', 64)->index();
            $table->string('host_id', 128)->index();
            $table->string('event_type', 60)->index();                  // process_started|process_terminated|...
            $table->unsignedBigInteger('sequence_id')->index();         // monotonic per-agent
            $table->string('batch_id', 48)->nullable()->index();
            $table->timestamp('occurred_at')->index();
            // Process context
            $table->integer('process_pid')->nullable();
            $table->string('process_name', 256)->nullable()->index();
            $table->string('process_path', 1024)->nullable();
            $table->string('process_cmdline', 2048)->nullable();
            $table->integer('parent_pid')->nullable();
            $table->string('parent_name', 256)->nullable();
            $table->unsignedSmallInteger('process_uid')->nullable();
            // Network context
            $table->string('connection_dest', 256)->nullable();
            $table->unsignedSmallInteger('connection_port')->nullable();
            $table->string('connection_proto', 16)->nullable();
            // Persistence context
            $table->string('persistence_key', 512)->nullable();
            $table->string('persistence_type', 64)->nullable();
            $table->string('persistence_path', 1024)->nullable();
            // Shell/response context
            $table->string('shell_hint', 256)->nullable();
            $table->string('response_exec_id', 40)->nullable()->index();
            // Replay and routing
            $table->string('trace_id', 48)->nullable()->index();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });

        // Per-agent sequence offset tracking — append-only (read latest per agent)
        Schema::create('endpoint_stream_offsets', function (Blueprint $table) {
            $table->id();
            $table->string('agent_id', 64)->index();
            $table->unsignedBigInteger('sequence_id');
            $table->string('batch_id', 48)->nullable();
            $table->unsignedInteger('event_count')->default(0);
            $table->string('trace_id', 48)->nullable();
            $table->timestamp('acknowledged_at')->useCurrent();
            // No updated_at — append-only (latest row = current offset)
        });

        // Stream checkpoint snapshots — append-only
        Schema::create('endpoint_stream_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->string('checkpoint_id', 48)->unique()->index();
            $table->string('agent_id', 64)->index();
            $table->unsignedBigInteger('sequence_id');
            $table->string('batch_id', 48)->nullable();
            $table->unsignedInteger('event_count')->default(0);
            $table->unsignedSmallInteger('stream_lag_seconds')->default(0);
            $table->unsignedInteger('dropped_event_count')->default(0);
            $table->string('trace_id', 48)->nullable();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });

        // Stream health snapshots — append-only (latest row per agent = current health)
        Schema::create('endpoint_stream_health', function (Blueprint $table) {
            $table->id();
            $table->string('agent_id', 64)->index();
            $table->string('stream_health_state', 30)->default('healthy')->index(); // healthy|lagging|degraded|offline
            $table->unsignedBigInteger('last_sequence_seen')->default(0);
            $table->unsignedSmallInteger('stream_lag_seconds')->default(0);
            $table->unsignedInteger('dropped_event_count')->default(0);
            $table->unsignedInteger('batch_count')->default(0);
            $table->string('trace_id', 48)->nullable();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_stream_health');
        Schema::dropIfExists('endpoint_stream_checkpoints');
        Schema::dropIfExists('endpoint_stream_offsets');
        Schema::dropIfExists('endpoint_stream_events');
    }
};
