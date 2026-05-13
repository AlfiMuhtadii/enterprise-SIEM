<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_threat_intel_lookups', function (Blueprint $table) {
            $table->id();
            $table->string('lookup_id', 80)->unique();
            $table->string('provider', 80)->index();
            $table->string('indicator_type', 32)->index();
            $table->string('indicator_value', 512)->index();
            $table->string('status', 32)->default('completed')->index();
            $table->string('reputation', 32)->default('unknown')->index();
            $table->float('score')->nullable();
            $table->jsonb('result')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('requested_by', 120)->default('system')->index();
            $table->timestampTz('looked_up_at')->index();
            $table->timestampsTz();
        });

        Schema::create('external_ioc_feeds', function (Blueprint $table) {
            $table->id();
            $table->string('feed_id', 80)->unique();
            $table->string('feed_type', 80)->index();
            $table->string('name', 160);
            $table->string('source_url', 500)->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->timestampTz('last_imported_at')->nullable()->index();
            $table->unsignedInteger('last_import_count')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->string('created_by', 120)->default('system')->index();
            $table->timestampsTz();
        });

        Schema::create('rag_retrieval_runs', function (Blueprint $table) {
            $table->id();
            $table->string('retrieval_id', 80)->unique();
            $table->string('target_type', 64)->index();
            $table->string('target_id', 128)->nullable()->index();
            $table->string('vector_store', 80)->default('local-keyword')->index();
            $table->string('embedding_provider', 80)->default('local-keyword')->index();
            $table->jsonb('query_terms')->nullable();
            $table->jsonb('citations')->nullable();
            $table->float('citation_quality_score')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestampTz('retrieved_at')->index();
            $table->timestampsTz();
        });

        Schema::create('ai_evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('eval_id', 80)->unique();
            $table->string('scope', 80)->default('recent')->index();
            $table->timestampTz('evaluated_at')->index();
            $table->jsonb('metrics');
            $table->jsonb('samples')->nullable();
            $table->string('generated_by', 120)->default('system')->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_evaluation_runs');
        Schema::dropIfExists('rag_retrieval_runs');
        Schema::dropIfExists('external_ioc_feeds');
        Schema::dropIfExists('external_threat_intel_lookups');
    }
};
