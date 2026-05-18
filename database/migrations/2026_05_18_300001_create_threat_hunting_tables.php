<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hunt session record — one row per executed hunt, append-only
        Schema::create('threat_hunts', function (Blueprint $table) {
            $table->id();
            $table->string('hunt_id', 40)->unique()->index();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('executed_at')->index();
            $table->string('replay_scope', 60)->default('live'); // live | replay
            $table->string('status', 30)->default('completed'); // completed | empty | failed
            $table->unsignedInteger('result_count')->default(0);
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamp('created_at');
        });

        // Structured query parameters per hunt — append-only
        Schema::create('threat_hunt_queries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hunt_id');
            $table->foreign('hunt_id')->references('id')->on('threat_hunts')->cascadeOnDelete();
            $table->string('query_domain', 60)->index();
            $table->jsonb('query_filters');              // [{field, operator, value}]
            $table->timestamp('time_range_start')->nullable();
            $table->timestamp('time_range_end')->nullable();
            $table->unsignedInteger('max_results')->default(100);
            $table->timestamp('created_at');
        });

        // Hunt result records — snapshot of matching data, append-only
        Schema::create('threat_hunt_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hunt_id');
            $table->foreign('hunt_id')->references('id')->on('threat_hunts')->cascadeOnDelete();
            $table->string('result_type', 60)->index();  // process_entry|persistence_item|finding|...
            $table->unsignedBigInteger('result_source_id')->nullable(); // FK into source table
            $table->jsonb('result_data');                // snapshot of the matching record
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threat_hunt_results');
        Schema::dropIfExists('threat_hunt_queries');
        Schema::dropIfExists('threat_hunts');
    }
};
