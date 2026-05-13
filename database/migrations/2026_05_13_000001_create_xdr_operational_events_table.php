<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xdr_operational_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 80)->unique();
            $table->string('event_type', 120)->index();
            $table->unsignedInteger('schema_version')->default(1)->index();
            $table->string('source_topic', 120)->nullable()->index();
            $table->string('source_service', 120)->index();
            $table->string('aggregate_type', 80)->nullable()->index();
            $table->string('aggregate_id', 160)->nullable()->index();
            $table->string('trace_id', 120)->nullable()->index();
            $table->string('correlation_id', 120)->nullable()->index();
            $table->timestampTz('occurred_at')->index();
            $table->jsonb('payload');
            $table->jsonb('metadata')->nullable();
            $table->boolean('replayable')->default(true)->index();
            $table->timestampTz('published_at')->nullable()->index();
            $table->timestampsTz();

            $table->index(['event_type', 'occurred_at']);
            $table->index(['aggregate_type', 'aggregate_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xdr_operational_events');
    }
};
