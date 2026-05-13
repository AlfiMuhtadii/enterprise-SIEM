<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestampTz('ts')->index();
            $table->timestampTz('ingested_at')->useCurrent();
            $table->string('event_type', 64)->index();
            $table->string('event_hash', 64)->unique();
            $table->uuid('request_id')->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('email_hash', 64)->nullable();
            $table->string('method', 16)->nullable();
            $table->string('path', 1024)->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('query_hash', 64)->nullable();
            $table->boolean('has_sql_keywords')->default(false);
            $table->boolean('has_script_payload')->default(false);
            $table->json('payload')->nullable();

            $table->index(['ip', 'ts']);
            $table->index(['user_id', 'ts']);
            $table->index(['event_type', 'ts']);
            $table->index(['path', 'ts']);
            $table->index(['status', 'ts']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
