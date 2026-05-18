<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoint_response_commands', function (Blueprint $table) {
            $table->id();
            $table->string('command_id', 40)->unique()->index();
            $table->unsignedBigInteger('agent_id');
            $table->foreign('agent_id')->references('id')->on('endpoint_agents')->cascadeOnDelete();
            // Allowlisted safe types only — no isolate/kill/quarantine
            $table->string('command_type', 40)->index();
            $table->string('status', 30)->index();
            $table->jsonb('parameters')->nullable();
            $table->jsonb('result')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamps();
        });

        // Append-only audit trail — every state transition recorded
        Schema::create('endpoint_response_command_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('command_id');
            $table->foreign('command_id')->references('id')->on('endpoint_response_commands')->cascadeOnDelete();
            $table->string('event_type', 60);
            $table->string('from_state', 30)->nullable();
            $table->string('to_state', 30);
            $table->string('actor', 255)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->jsonb('details')->nullable();
            $table->string('trace_id', 120)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_response_command_events');
        Schema::dropIfExists('endpoint_response_commands');
    }
};
