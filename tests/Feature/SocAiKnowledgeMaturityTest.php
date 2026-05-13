<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SocAiKnowledgeMaturityTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyst_can_generate_and_review_ai_incident_suggestion(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->seedIncident('incident-ai-1');
        $this->seedAlert('alert-ai-1', 'incident-ai-1', 'BRUTE_FORCE_IP');

        $this->actingAs($analyst)
            ->post('/soc/incidents/incident-ai-1/ai', ['suggestion_type' => 'incident_summary'])
            ->assertRedirect();

        $suggestion = DB::table('ai_analyst_suggestions')->where('target_id', 'incident-ai-1')->first();
        $this->assertNotNull($suggestion);
        $this->assertSame('pending_review', $suggestion->status);

        $this->assertDatabaseHas('security_incident_notes', [
            'incident_id' => 'incident-ai-1',
            'note_type' => 'ai_suggestion',
        ]);

        $this->actingAs($analyst)
            ->post('/soc/ai/'.$suggestion->suggestion_id.'/review', [
                'status' => 'accepted',
                'review_note' => 'useful triage summary',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ai_analyst_suggestions', [
            'suggestion_id' => $suggestion->suggestion_id,
            'status' => 'accepted',
            'reviewed_by' => $analyst->email,
        ]);
    }

    public function test_knowledge_base_entry_can_be_created_and_searched(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($analyst)
            ->post('/soc/knowledge', [
                'title' => 'Brute force triage checklist',
                'entry_type' => 'investigation_template',
                'content_markdown' => 'Check source IP, account lockout, and MFA events.',
                'tags' => 'bruteforce,auth,mitre',
                'related_rule_id' => 'BRUTE_FORCE_IP',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('soc_knowledge_base', [
            'title' => 'Brute force triage checklist',
            'entry_type' => 'investigation_template',
            'related_rule_id' => 'BRUTE_FORCE_IP',
        ]);

        $this->actingAs($analyst)
            ->get('/soc/knowledge?q=Brute&tag=auth')
            ->assertOk()
            ->assertSee('Brute force triage checklist');
    }

    public function test_detection_maturity_monitor_records_quality_warning(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => 'old-alert-volume',
            'detected_at' => now()->subHours(36),
            'alert_type' => 'OLD',
            'detector_name' => 'OLD',
            'detector_version' => 'v1',
            'severity' => 'low',
            'score' => 0.1,
            'evidence' => json_encode([]),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        for ($i = 0; $i < 3; $i++) {
            DB::table('security_alerts')->insert([
                'alert_id' => 'new-alert-volume-'.$i,
                'detected_at' => now()->subMinutes($i + 1),
                'alert_type' => 'SPIKE',
                'detector_name' => 'SPIKE',
                'detector_version' => 'v1',
                'severity' => 'medium',
                'score' => 0.5,
                'evidence' => json_encode([]),
                'raw_event' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->artisan('soc:detection-maturity')->assertSuccessful();

        $this->assertDatabaseHas('detection_maturity_runs', [
            'run_type' => 'monitor',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('detection_quality_warnings', [
            'warning_type' => 'alert_volume_anomaly',
            'status' => 'open',
        ]);
    }

    public function test_soc_dashboard_shows_ai_visibility(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        DB::table('ai_analyst_suggestions')->insert([
            'suggestion_id' => 'ai-visible',
            'target_type' => 'incident',
            'target_id' => 'incident-visible',
            'suggestion_type' => 'incident_summary',
            'provider' => 'local-heuristic',
            'output' => json_encode(['summary' => 'visible summary']),
            'status' => 'pending_review',
            'requested_by' => $analyst->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($analyst)
            ->get('/soc')
            ->assertOk()
            ->assertSee('AI & Detection Maturity', false)
            ->assertSee('incident_summary');
    }

    private function seedIncident(string $incidentId): void
    {
        DB::table('security_incidents')->insert([
            'incident_id' => $incidentId,
            'title' => 'AI Test Incident',
            'status' => 'open',
            'severity' => 'high',
            'confidence' => 0.91,
            'first_seen_at' => now()->subMinutes(10),
            'last_seen_at' => now(),
            'affected_entities' => json_encode(['host-ai']),
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
            'actor_key' => 'host-ai',
            'incident_id' => $incidentId,
            'score' => 0.9,
            'evidence' => json_encode(['evidence_chain' => [$alertId]]),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
