<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xdr_service_health', function (Blueprint $table) {
            $table->id();
            $table->string('service_name', 80)->index();
            $table->string('status', 32)->default('unknown')->index();
            $table->jsonb('checks')->nullable();
            $table->jsonb('metrics')->nullable();
            $table->timestampTz('checked_at')->index();
            $table->timestampsTz();
            $table->unique(['service_name', 'checked_at']);
        });

        Schema::create('xdr_stream_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('topic', 120)->index();
            $table->string('consumer_group', 120)->index();
            $table->unsignedBigInteger('produced_count')->default(0);
            $table->unsignedBigInteger('consumed_count')->default(0);
            $table->unsignedBigInteger('dead_letter_count')->default(0);
            $table->unsignedBigInteger('retry_count')->default(0);
            $table->unsignedBigInteger('consumer_lag')->default(0)->index();
            $table->float('throughput_eps')->nullable();
            $table->float('avg_processing_latency_ms')->nullable();
            $table->timestampTz('measured_at')->index();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['topic', 'consumer_group', 'measured_at']);
        });

        Schema::create('xdr_storage_health', function (Blueprint $table) {
            $table->id();
            $table->string('store_name', 80)->index();
            $table->string('driver', 40)->index();
            $table->string('status', 32)->default('unknown')->index();
            $table->unsignedInteger('retention_days')->nullable();
            $table->float('query_latency_ms')->nullable();
            $table->jsonb('metrics')->nullable();
            $table->timestampTz('checked_at')->index();
            $table->timestampsTz();
        });

        Schema::create('xdr_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 80)->unique();
            $table->string('dataset_name', 160)->index();
            $table->string('mode', 40)->default('mixed_replay')->index();
            $table->string('status', 32)->default('completed')->index();
            $table->jsonb('domain_metrics');
            $table->jsonb('quality_metrics');
            $table->jsonb('throughput_metrics')->nullable();
            $table->jsonb('latency_metrics')->nullable();
            $table->timestampTz('started_at')->nullable()->index();
            $table->timestampTz('completed_at')->nullable()->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xdr_validation_runs');
        Schema::dropIfExists('xdr_storage_health');
        Schema::dropIfExists('xdr_stream_metrics');
        Schema::dropIfExists('xdr_service_health');
    }
};
