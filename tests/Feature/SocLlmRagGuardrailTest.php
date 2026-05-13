<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocLlmRagGuardrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_generation_uses_configured_remote_provider_with_local_fallback_and_execution_history(): void
    {
        Config::set('soc.ai_provider', 'openai-compatible');
        Config::set('soc.openai_compatible_base_url', '');
        Config::set('soc.openai_compatible_api_key', '');

        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->seedIncident('incident-llm-1');
        $this->seedAlert('alert-llm-1', 'incident-llm-1', 'BRUTE_FORCE_IP');
        $this->seedKnowledge($analyst->email, 'BRUTE_FORCE_IP');

        $this->actingAs($analyst)
            ->post('/soc/incidents/incident-llm-1/ai', ['suggestion_type' => 'incident_summary'])
            ->assertRedirect();

        $suggestion = DB::table('ai_analyst_suggestions')->where('target_id', 'incident-llm-1')->first();
        $this->assertNotNull($suggestion);
        $this->assertSame('openai-compatible', $suggestion->provider);
        $this->assertSame('passed', $suggestion->guardrail_status);
        $this->assertNotEmpty($suggestion->retrieval_citations);
        $this->assertNotEmpty($suggestion->trace_id);

        $this->assertDatabaseHas('ai_execution_history', [
            'trace_id' => $suggestion->trace_id,
            'provider' => 'openai-compatible',
            'status' => 'completed',
        ]);
    }

    public function test_knowledge_entry_creates_local_embedding_for_retrieval(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($analyst)->post('/soc/knowledge', [
            'title' => 'SQL injection investigation note',
            'entry_type' => 'rule_doc',
            'content_markdown' => 'Review injection payload, request body, and database errors.',
            'tags' => 'injection,web',
            'related_rule_id' => 'INJECTION_INDICATOR',
        ])->assertRedirect();

        $entry = DB::table('soc_knowledge_base')->where('related_rule_id', 'INJECTION_INDICATOR')->first();
        $this->assertNotNull($entry);
        $this->assertDatabaseHas('soc_knowledge_embeddings', [
            'kb_id' => $entry->kb_id,
            'embedding_provider' => 'local-keyword',
        ]);
    }

    public function test_dashboard_exposes_ai_operations_visibility(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        DB::table('ai_execution_history')->insert([
            'trace_id' => 'trace-dashboard',
            'provider' => 'local-heuristic',
            'model' => 'local-heuristic',
            'status' => 'completed',
            'latency_ms' => 12,
            'executed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ai_analyst_suggestions')->insert([
            'suggestion_id' => 'ai-dashboard',
            'target_type' => 'incident',
            'target_id' => 'incident-dashboard',
            'suggestion_type' => 'incident_summary',
            'provider' => 'local-heuristic',
            'model' => 'local-heuristic',
            'confidence_label' => 'high',
            'guardrail_status' => 'passed',
            'retrieval_citations' => json_encode([['kb_id' => 'kb-test']]),
            'trace_id' => 'trace-dashboard',
            'output' => json_encode(['summary' => 'dashboard']),
            'status' => 'rejected',
            'requested_by' => $analyst->email,
            'reviewed_by' => $analyst->email,
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($analyst)
            ->get('/soc')
            ->assertOk()
            ->assertSee('Provider / Model Usage')
            ->assertSee('Confidence / Retrieval')
            ->assertSee('local-heuristic');
    }

    public function test_ai_generation_can_use_standalone_ai_rag_service_with_trace_propagation(): void
    {
        Config::set('soc.ai_service_enabled', true);
        Config::set('soc.ai_service_url', 'http://ai-rag.test');
        Http::fake([
            'http://ai-rag.test/health' => Http::response(['status' => 'ok', 'service' => 'ai-rag'], 200),
            'http://ai-rag.test/v1/analyze' => Http::response([
                'incident_id' => 'incident-rag-1',
                'provider' => 'heuristic',
                'confidence' => 'medium',
                'summary' => 'Standalone AI/RAG service summary.',
                'recommended_steps' => ['Review evidence.'],
                'recommended_responses' => ['No automated response without approval.'],
                'citations' => ['alert-rag-1'],
                'safety' => ['mode' => 'defensive_only'],
            ], 200),
        ]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->seedIncident('incident-rag-1');
        $this->seedAlert('alert-rag-1', 'incident-rag-1', 'IDENTITY_RISKY_IP_LOGIN');

        $this->actingAs($analyst)
            ->post('/soc/incidents/incident-rag-1/ai', ['suggestion_type' => 'incident_summary'])
            ->assertRedirect();

        $suggestion = DB::table('ai_analyst_suggestions')->where('target_id', 'incident-rag-1')->first();
        $this->assertNotNull($suggestion);
        $this->assertSame('ai-rag-service', $suggestion->provider);
        $this->assertStringContainsString('Standalone AI/RAG service summary', $suggestion->output);
        Http::assertSent(fn ($request) => $request->url() === 'http://ai-rag.test/v1/analyze' && $request->hasHeader('X-Trace-Id'));
    }

    private function seedIncident(string $incidentId): void
    {
        DB::table('security_incidents')->insert([
            'incident_id' => $incidentId,
            'title' => 'LLM Test Incident',
            'status' => 'open',
            'severity' => 'high',
            'confidence' => 0.9,
            'first_seen_at' => now()->subMinutes(10),
            'last_seen_at' => now(),
            'affected_entities' => json_encode(['host-llm']),
            'timeline' => json_encode([]),
            'mitre_mapping' => json_encode(['T1110']),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedAlert(string $alertId, string $incidentId, string $type): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => $alertId,
            'detected_at' => now(),
            'alert_type' => $type,
            'detector_name' => $type,
            'detector_version' => 'v1',
            'severity' => 'high',
            'actor_key' => 'host-llm',
            'incident_id' => $incidentId,
            'score' => 0.9,
            'evidence' => json_encode(['evidence_chain' => [$alertId]]),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedKnowledge(string $actor, string $rule): void
    {
        DB::table('soc_knowledge_base')->insert([
            'kb_id' => 'kb-llm',
            'title' => 'Brute force defensive triage',
            'entry_type' => 'investigation_template',
            'content_markdown' => 'Validate failed login volume, account lockouts, and source IP reputation.',
            'tags' => json_encode(['bruteforce', 'auth']),
            'related_rule_id' => $rule,
            'created_by' => $actor,
            'updated_by' => $actor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
