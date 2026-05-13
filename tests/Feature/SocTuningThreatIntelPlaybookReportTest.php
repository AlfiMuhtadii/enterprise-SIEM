<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SocTuningThreatIntelPlaybookReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyst_can_record_alert_feedback_and_apply_suppression(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->seedAlert('alert-tune-1', 'NOISY_RULE', '10.1.1.1');

        $this->actingAs($analyst)->get('/soc/tuning')->assertOk();

        $this->actingAs($analyst)->post('/soc/tuning/alerts/alert-tune-1', [
            'verdict' => 'false_positive',
            'reason' => 'known scanner',
            'notes' => 'internal validation traffic',
            'rule_id' => 'NOISY_RULE',
        ])->assertRedirect();

        $this->assertDatabaseHas('alert_feedback', [
            'alert_id' => 'alert-tune-1',
            'verdict' => 'false_positive',
            'analyst' => $analyst->email,
        ]);

        $this->actingAs($analyst)->post('/soc/tuning/suppressions', [
            'scope' => 'alert_type',
            'match_value' => 'NOISY_RULE',
            'reason' => 'noise during test',
            'expires_in_hours' => 2,
        ])->assertRedirect();

        $this->actingAs($analyst)->post('/soc/tuning/suppressions/apply')->assertRedirect();

        $this->assertDatabaseHas('security_alerts', [
            'alert_id' => 'alert-tune-1',
            'is_suppressed' => true,
        ]);
        $this->assertDatabaseHas('alert_suppression_history', [
            'alert_id' => 'alert-tune-1',
            'suppressed_by' => $analyst->email,
        ]);
    }

    public function test_ioc_manual_entry_import_and_alert_enrichment(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->seedAlert('alert-ioc-1', 'NETWORK_IOC', '203.0.113.44');

        $this->actingAs($analyst)->get('/soc/threat-intel')->assertOk();

        $this->actingAs($analyst)->post('/soc/threat-intel/iocs', [
            'ioc_type' => 'ip',
            'ioc_value' => '203.0.113.44',
            'source' => 'manual-test',
            'reputation' => 'malicious',
            'threat_label' => 'test-c2',
            'expires_in_days' => 7,
        ])->assertRedirect();

        $this->assertDatabaseHas('threat_iocs', [
            'ioc_type' => 'ip',
            'ioc_value' => '203.0.113.44',
            'reputation' => 'malicious',
        ]);

        $this->actingAs($analyst)->post('/soc/threat-intel/import', [
            'feed_format' => 'jsonl',
            'source' => 'unit-feed',
            'feed_body' => '{"ioc_type":"domain","ioc_value":"evil.example","reputation":"suspicious"}',
        ])->assertRedirect();

        $this->assertDatabaseHas('threat_iocs', [
            'ioc_type' => 'domain',
            'ioc_value' => 'evil.example',
        ]);

        $this->actingAs($analyst)->post('/soc/threat-intel/enrich')->assertRedirect();

        $this->assertDatabaseHas('ioc_hits', [
            'alert_id' => 'alert-ioc-1',
            'matched_value' => '203.0.113.44',
        ]);
    }

    public function test_playbook_template_and_task_progress_are_tracked(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->seedIncident('incident-playbook-1');

        $this->actingAs($analyst)->post('/soc/incidents/incident-playbook-1/playbooks', [
            'name' => 'Endpoint Investigation',
            'incident_type' => 'endpoint',
            'assigned_analyst' => $analyst->email,
            'template' => 'endpoint_compromise',
        ])->assertRedirect();

        $playbook = DB::table('incident_playbooks')->where('incident_id', 'incident-playbook-1')->first();
        $this->assertNotNull($playbook);
        $task = DB::table('incident_playbook_tasks')->where('playbook_id', $playbook->playbook_id)->orderBy('sort_order')->first();

        $this->actingAs($analyst)->post('/soc/playbook-tasks/'.$task->task_id, [
            'status' => 'completed',
            'assigned_to' => $analyst->email,
            'note' => 'done',
        ])->assertRedirect();

        $this->assertDatabaseHas('incident_playbook_tasks', [
            'task_id' => $task->task_id,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('security_audit_trails', [
            'actor' => $analyst->email,
            'action' => 'playbook.task_update',
            'target_id' => $task->task_id,
        ]);
    }

    public function test_executive_report_can_be_generated_as_html_and_json(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->seedIncident('incident-report-1');
        $this->seedAlert('alert-report-1', 'BRUTE_FORCE_IP', '198.51.100.9');

        $this->actingAs($analyst)->get('/soc/reports')->assertOk();

        $this->actingAs($analyst)->post('/soc/reports/generate', [
            'period' => 'weekly',
        ])->assertOk()->assertSee('Security Report');

        $report = DB::table('executive_security_reports')->first();
        $this->assertNotNull($report);

        $this->actingAs($analyst)->get('/soc/reports/'.$report->report_id.'/json')
            ->assertOk()
            ->assertJsonPath('report_id', $report->report_id);
    }

    private function seedAlert(string $alertId, string $type, string $ip): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => $alertId,
            'alert_fingerprint' => 'fp-'.$alertId,
            'dedup_group' => $type.'|'.$ip,
            'is_suppressed' => false,
            'detected_at' => now(),
            'alert_type' => $type,
            'detector_name' => $type,
            'detector_version' => 'v1',
            'severity' => 'medium',
            'ip' => $ip,
            'actor_key' => $ip,
            'score' => 0.8,
            'evidence' => json_encode(['ip' => $ip, 'domain' => 'evil.example']),
            'raw_event' => json_encode(['src_ip' => $ip]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedIncident(string $incidentId): void
    {
        DB::table('security_incidents')->insert([
            'incident_id' => $incidentId,
            'title' => 'Unit Test Incident',
            'status' => 'open',
            'severity' => 'high',
            'confidence' => 0.9,
            'assigned_analyst' => null,
            'first_seen_at' => now()->subMinutes(10),
            'last_seen_at' => now(),
            'affected_entities' => json_encode(['host-1']),
            'timeline' => json_encode([]),
            'mitre_mapping' => json_encode(['T1110']),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
