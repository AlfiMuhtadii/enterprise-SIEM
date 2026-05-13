<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_analyst_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('suggestion_id', 80)->unique();
            $table->string('target_type', 64)->index();
            $table->string('target_id', 128)->nullable()->index();
            $table->string('suggestion_type', 80)->index();
            $table->string('provider', 80)->default('local')->index();
            $table->jsonb('input_context')->nullable();
            $table->jsonb('output')->nullable();
            $table->string('status', 32)->default('pending_review')->index();
            $table->string('requested_by', 120)->index();
            $table->string('reviewed_by', 120)->nullable()->index();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();
        });

        Schema::create('soc_knowledge_base', function (Blueprint $table) {
            $table->id();
            $table->string('kb_id', 80)->unique();
            $table->string('title', 220);
            $table->string('entry_type', 80)->index();
            $table->text('content_markdown');
            $table->jsonb('tags')->nullable();
            $table->string('related_incident_id', 80)->nullable()->index();
            $table->string('related_rule_id', 120)->nullable()->index();
            $table->string('related_ioc_id', 80)->nullable()->index();
            $table->string('created_by', 120)->index();
            $table->string('updated_by', 120)->nullable()->index();
            $table->timestampsTz();
            $table->index(['entry_type', 'created_at']);
        });

        Schema::create('detection_maturity_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 80)->unique();
            $table->string('run_type', 80)->index();
            $table->string('status', 32)->default('completed')->index();
            $table->timestampTz('started_at')->index();
            $table->timestampTz('completed_at')->nullable();
            $table->jsonb('metrics');
            $table->jsonb('warnings')->nullable();
            $table->string('generated_by', 120)->default('system')->index();
            $table->timestampsTz();
        });

        Schema::create('detection_quality_warnings', function (Blueprint $table) {
            $table->id();
            $table->string('warning_id', 80)->unique();
            $table->string('warning_type', 80)->index();
            $table->string('severity', 32)->default('medium')->index();
            $table->text('message');
            $table->jsonb('evidence')->nullable();
            $table->timestampTz('detected_at')->index();
            $table->string('status', 32)->default('open')->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detection_quality_warnings');
        Schema::dropIfExists('detection_maturity_runs');
        Schema::dropIfExists('soc_knowledge_base');
        Schema::dropIfExists('ai_analyst_suggestions');
    }
};
