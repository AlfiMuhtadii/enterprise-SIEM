<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SocExternalTiAdvancedRagAiEvalTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_lookup_uses_local_fallback_and_records_history(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        DB::table('threat_iocs')->insert([
            'ioc_id' => 'ioc-external',
            'ioc_type' => 'ip',
            'ioc_value' => '203.0.113.200',
            'source' => 'unit',
            'reputation' => 'malicious',
            'enabled' => true,
            'created_by' => $analyst->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($analyst)->post('/soc/threat-intel/lookup', [
            'provider' => 'virustotal',
            'indicator_type' => 'ip',
            'indicator_value' => '203.0.113.200',
        ])->assertRedirect();

        $this->assertDatabaseHas('external_threat_intel_lookups', [
            'provider' => 'virustotal',
            'indicator_value' => '203.0.113.200',
            'reputation' => 'malicious',
        ]);
    }

    public function test_misp_style_feed_imports_iocs_and_tracks_feed(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($analyst)->post('/soc/threat-intel/external-feed', [
            'feed_type' => 'misp-json',
            'source' => 'unit-misp',
            'feed_body' => json_encode([
                ['type' => 'domain', 'value' => 'evil.example', 'reputation' => 'malicious', 'label' => 'unit-malware'],
            ]),
        ])->assertRedirect();

        $this->assertDatabaseHas('threat_iocs', [
            'ioc_type' => 'domain',
            'ioc_value' => 'evil.example',
            'reputation' => 'malicious',
        ]);
        $this->assertDatabaseHas('external_ioc_feeds', [
            'feed_type' => 'misp-json',
            'name' => 'unit-misp',
            'last_import_count' => 1,
        ]);
    }

    public function test_ai_generation_records_rag_retrieval_run_and_ai_evaluation_metrics(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->seedIncident('incident-rag-eval');
        $this->seedAlert('alert-rag-eval', 'incident-rag-eval', 'BRUTE_FORCE_IP');
        DB::table('soc_knowledge_base')->insert([
            'kb_id' => 'kb-rag-eval',
            'title' => 'Brute force playbook',
            'entry_type' => 'investigation_template',
            'content_markdown' => 'BRUTE_FORCE_IP investigation should validate failed login count and source IP reputation.',
            'tags' => json_encode(['bruteforce']),
            'related_rule_id' => 'BRUTE_FORCE_IP',
            'created_by' => $analyst->email,
            'updated_by' => $analyst->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('soc_knowledge_embeddings')->insert([
            'kb_id' => 'kb-rag-eval',
            'embedding_provider' => 'local-keyword',
            'embedding' => json_encode(['brute_force_ip' => 3, 'failed' => 1, 'login' => 1]),
            'metadata' => json_encode([]),
            'embedded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($analyst)->post('/soc/incidents/incident-rag-eval/ai', [
            'suggestion_type' => 'incident_summary',
        ])->assertRedirect();

        $this->assertDatabaseHas('rag_retrieval_runs', [
            'target_id' => 'incident-rag-eval',
            'vector_store' => 'local-keyword',
        ]);

        $suggestion = DB::table('ai_analyst_suggestions')->where('target_id', 'incident-rag-eval')->first();
        $this->actingAs($analyst)->post('/soc/ai/'.$suggestion->suggestion_id.'/review', [
            'status' => 'accepted',
        ])->assertRedirect();

        $this->artisan('soc:ai-evaluate --days=7')->assertSuccessful();
        $this->assertDatabaseHas('ai_evaluation_runs', ['scope' => '7d']);
    }

    private function seedIncident(string $incidentId): void
    {
        DB::table('security_incidents')->insert([
            'incident_id' => $incidentId,
            'title' => 'RAG Eval Incident',
            'status' => 'open',
            'severity' => 'high',
            'confidence' => 0.9,
            'first_seen_at' => now()->subMinutes(10),
            'last_seen_at' => now(),
            'affected_entities' => json_encode(['host-rag']),
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
            'actor_key' => 'host-rag',
            'incident_id' => $incidentId,
            'score' => 0.9,
            'evidence' => json_encode(['evidence_chain' => [$alertId]]),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
