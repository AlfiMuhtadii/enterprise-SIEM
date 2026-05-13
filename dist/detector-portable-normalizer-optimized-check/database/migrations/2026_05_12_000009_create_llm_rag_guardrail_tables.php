<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_analyst_suggestions', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_analyst_suggestions', 'model')) {
                $table->string('model', 120)->nullable()->after('provider')->index();
            }
            if (!Schema::hasColumn('ai_analyst_suggestions', 'latency_ms')) {
                $table->unsignedInteger('latency_ms')->nullable()->after('model');
            }
            if (!Schema::hasColumn('ai_analyst_suggestions', 'token_usage')) {
                $table->jsonb('token_usage')->nullable()->after('latency_ms');
            }
            if (!Schema::hasColumn('ai_analyst_suggestions', 'confidence_label')) {
                $table->string('confidence_label', 32)->default('medium')->after('token_usage')->index();
            }
            if (!Schema::hasColumn('ai_analyst_suggestions', 'guardrail_status')) {
                $table->string('guardrail_status', 32)->default('passed')->after('confidence_label')->index();
            }
            if (!Schema::hasColumn('ai_analyst_suggestions', 'retrieval_citations')) {
                $table->jsonb('retrieval_citations')->nullable()->after('guardrail_status');
            }
            if (!Schema::hasColumn('ai_analyst_suggestions', 'trace_id')) {
                $table->string('trace_id', 80)->nullable()->after('retrieval_citations')->index();
            }
        });

        Schema::create('ai_execution_history', function (Blueprint $table) {
            $table->id();
            $table->string('trace_id', 80)->unique();
            $table->string('suggestion_id', 80)->nullable()->index();
            $table->string('provider', 80)->index();
            $table->string('model', 120)->nullable()->index();
            $table->string('prompt_template_id', 80)->nullable()->index();
            $table->string('status', 32)->default('completed')->index();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->jsonb('token_usage')->nullable();
            $table->jsonb('provider_metadata')->nullable();
            $table->jsonb('prompt_trace')->nullable();
            $table->jsonb('response_validation')->nullable();
            $table->jsonb('guardrail_result')->nullable();
            $table->timestampTz('executed_at')->index();
            $table->timestampsTz();
        });

        Schema::create('ai_prompt_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_id', 80)->unique();
            $table->string('name', 160);
            $table->string('task_type', 80)->index();
            $table->text('system_prompt');
            $table->text('user_template');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('enabled')->default(true)->index();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('soc_knowledge_embeddings', function (Blueprint $table) {
            $table->id();
            $table->string('kb_id', 80)->unique();
            $table->string('embedding_provider', 80)->default('local-keyword')->index();
            $table->jsonb('embedding');
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('embedded_at')->index();
            $table->timestampsTz();
        });

        Schema::create('ai_guardrail_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 80)->unique();
            $table->string('trace_id', 80)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('severity', 32)->default('medium')->index();
            $table->text('message');
            $table->jsonb('evidence')->nullable();
            $table->timestampTz('detected_at')->index();
            $table->timestampsTz();
        });

        DB::table('ai_prompt_templates')->insert([
            [
                'template_id' => 'soc-default-incident',
                'name' => 'Defensive SOC Incident Analysis',
                'task_type' => 'incident_summary',
                'system_prompt' => 'You are a defensive SOC analyst assistant. Use only provided incident evidence, MITRE mapping, IOC context, and knowledge citations. Do not provide offensive instructions.',
                'user_template' => 'Task: {{type}}. Context JSON: {{context}}. Return strict JSON with summary, explanation, recommended_steps, recommended_responses, confidence, and citations_used.',
                'version' => 1,
                'enabled' => true,
                'metadata' => json_encode(['safety' => 'defensive-only']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_id' => 'soc-default-assist',
                'name' => 'Defensive SOC Analyst Assist',
                'task_type' => 'analyst_assist',
                'system_prompt' => 'You are a defensive SOC analyst assistant. Summarize and explain evidence. Never provide exploit, evasion, persistence, credential theft, or C2 instructions.',
                'user_template' => 'Task: {{type}}. Context JSON: {{context}}. Return strict JSON with summary, recommended_steps, recommended_responses, confidence, and limitations.',
                'version' => 1,
                'enabled' => true,
                'metadata' => json_encode(['safety' => 'defensive-only']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_guardrail_events');
        Schema::dropIfExists('soc_knowledge_embeddings');
        Schema::dropIfExists('ai_prompt_templates');
        Schema::dropIfExists('ai_execution_history');

        Schema::table('ai_analyst_suggestions', function (Blueprint $table) {
            foreach (['model', 'latency_ms', 'token_usage', 'confidence_label', 'guardrail_status', 'retrieval_citations', 'trace_id'] as $column) {
                if (Schema::hasColumn('ai_analyst_suggestions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
