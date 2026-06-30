<?php

namespace Tests\Feature;

use App\Support\AiAnalystManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AI-CONTEXT-EMPTY — the compacted LLM context must carry actual alert details
 * and retrieved knowledge text (not just counts), so the model is not blind.
 */
class AiContextEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private function seedIncidentWithAlert(): void
    {
        DB::table('security_incidents')->insert([
            'incident_id' => 'INC-AI-1',
            'title' => 'Suspicious lateral movement',
            'status' => 'open',
            'severity' => 'high',
            'confidence' => 0.9,
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now(),
            'affected_entities' => json_encode(['host-1']),
            'timeline' => json_encode([]),
            'mitre_mapping' => json_encode(['T1021']),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('security_alerts')->insert([
            'alert_id' => 'alert-ai-1',
            'alert_fingerprint' => 'fp-ai-1',
            'dedup_group' => 'g',
            'incident_id' => 'INC-AI-1',
            'is_suppressed' => false,
            'detected_at' => now(),
            'alert_type' => 'LATERAL_MOVEMENT_SUSPECTED',
            'detector_name' => 'lateral-detector',
            'detector_version' => 'v1',
            'severity' => 'high',
            'ip' => '10.4.4.4',
            'actor_key' => '10.4.4.4',
            'score' => 0.91,
            'evidence' => json_encode(['signal' => 'smb-admin-share-access', 'host' => 'host-1']),
            'raw_event' => json_encode(['x' => 1]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function compactFor(string $incidentId): array
    {
        $manager = new AiAnalystManager();
        $context = $manager->incidentContext($incidentId);
        // mimic generateForIncident: attach citations then compact
        $context['retrieval_citations'] = [[
            'kb_id' => 'kb-1',
            'title' => 'Lateral movement triage playbook',
            'excerpt' => 'Investigate SMB admin share access and correlate with prior auth events.',
            'score' => 0.8,
            'related_rule_id' => 'lateral-detector',
        ]];

        // compactContext is private; exercise it through renderPrompt output.
        $ref = new \ReflectionMethod($manager, 'compactContext');
        $ref->setAccessible(true);

        return $ref->invoke($manager, $context);
    }

    public function test_compact_context_includes_alert_details_not_just_count(): void
    {
        $this->seedIncidentWithAlert();
        $compact = $this->compactFor('INC-AI-1');

        $this->assertSame(1, $compact['alert_count']);
        $this->assertArrayHasKey('alerts', $compact);
        $this->assertNotEmpty($compact['alerts']);
        $this->assertSame('LATERAL_MOVEMENT_SUSPECTED', $compact['alerts'][0]['alert_type']);
        $this->assertSame('high', $compact['alerts'][0]['severity']);
        $this->assertStringContainsString('smb-admin-share-access', $compact['alerts'][0]['evidence']);
    }

    public function test_compact_context_includes_retrieved_knowledge_text(): void
    {
        $this->seedIncidentWithAlert();
        $compact = $this->compactFor('INC-AI-1');

        $this->assertArrayHasKey('knowledge', $compact);
        $this->assertNotEmpty($compact['knowledge']);
        $this->assertSame('Lateral movement triage playbook', $compact['knowledge'][0]['title']);
        $this->assertStringContainsString('SMB admin share', $compact['knowledge'][0]['excerpt']);
    }

    public function test_compact_context_bounds_alerts_to_eight(): void
    {
        DB::table('security_incidents')->insert([
            'incident_id' => 'INC-AI-2', 'title' => 't', 'status' => 'open', 'severity' => 'low',
            'confidence' => 0.5, 'first_seen_at' => now(), 'last_seen_at' => now(),
            'affected_entities' => json_encode([]), 'timeline' => json_encode([]),
            'mitre_mapping' => json_encode([]), 'metadata' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($i = 0; $i < 12; $i++) {
            DB::table('security_alerts')->insert([
                'alert_id' => "a-$i", 'alert_fingerprint' => "fp-$i", 'dedup_group' => 'g',
                'incident_id' => 'INC-AI-2', 'is_suppressed' => false, 'detected_at' => now()->subMinutes($i),
                'alert_type' => 'X', 'detector_name' => 'd', 'detector_version' => 'v1', 'severity' => 'low',
                'ip' => '1.1.1.1', 'actor_key' => '1.1.1.1', 'score' => 0.5,
                'evidence' => json_encode([]), 'raw_event' => json_encode([]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $compact = $this->compactFor('INC-AI-2');
        $this->assertSame(12, $compact['alert_count']);
        $this->assertLessThanOrEqual(8, count($compact['alerts']));
    }
}
