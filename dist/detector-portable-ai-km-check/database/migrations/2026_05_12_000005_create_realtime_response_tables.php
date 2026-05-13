<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_stream_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('agent_id', 96)->index();
            $table->timestampTz('reported_at')->index();
            $table->string('stream_status', 32)->default('healthy')->index();
            $table->unsignedBigInteger('events_streamed_total')->default(0);
            $table->unsignedBigInteger('events_dropped_total')->default(0);
            $table->unsignedBigInteger('stream_retry_total')->default(0);
            $table->float('avg_event_latency_ms')->nullable();
            $table->unsignedInteger('buffer_depth')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['agent_id', 'reported_at']);
        });

        Schema::create('soc_response_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('response_id', 80)->unique();
            $table->string('source_type', 32)->index(); // alert|incident
            $table->string('source_id', 96)->index();
            $table->string('agent_id', 96)->nullable()->index();
            $table->string('action_type', 64)->index();
            $table->string('status', 32)->default('pending_approval')->index();
            $table->string('severity', 16)->default('medium')->index();
            $table->string('recommended_by', 120)->default('system')->index();
            $table->string('approved_by', 120)->nullable()->index();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->jsonb('suggestion')->nullable();
            $table->jsonb('execution_result')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soc_response_workflows');
        Schema::dropIfExists('agent_stream_metrics');
    }
};
