<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SocInvestigationHuntingTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyst_can_run_save_and_export_threat_hunt(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);

        DB::table('telemetry_events')->insert([
            'ts' => now(),
            'event_id' => 'telemetry-hunt-1',
            'telemetry_type' => 'endpoint',
            'event_type' => 'process_created',
            'host_id' => 'host-hunt',
            'src_ip' => '10.0.0.5',
            'dst_ip' => '198.51.100.20',
            'dst_port' => 443,
            'protocol' => 'tcp',
            'process_name' => 'powershell.exe',
            'user_name_hash' => 'user-hash',
            'payload' => json_encode(['domain' => 'example.test']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('security_alerts')->insert([
            'alert_id' => 'alert-hunt-1',
            'alert_fingerprint' => 'fp-hunt-1',
            'dedup_group' => 'host-hunt',
            'detected_at' => now(),
            'alert_type' => 'SUSPICIOUS_PROCESS',
            'detector_name' => 'test',
            'detector_version' => 'v1',
            'severity' => 'high',
            'actor_key' => 'host-hunt',
            'score' => 0.9,
            'evidence' => json_encode(['evidence_chain' => ['telemetry-hunt-1']]),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($analyst)
            ->get('/soc/hunts?run=1&host_id=host-hunt&process=powershell&minutes=60')
            ->assertOk()
            ->assertSee('powershell.exe')
            ->assertSee('SUSPICIOUS_PROCESS');

        $this->assertDatabaseHas('soc_hunt_run_sessions', [
            'executed_by' => $analyst->email,
            'result_count' => 1,
        ]);

        $this->actingAs($analyst)
            ->post('/soc/hunts/save', [
                'name' => 'PowerShell Hunt',
                'host_id' => 'host-hunt',
                'process' => 'powershell',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('soc_hunt_sessions', [
            'name' => 'PowerShell Hunt',
            'created_by' => $analyst->email,
            'saved' => true,
        ]);

        $this->actingAs($analyst)
            ->get('/soc/hunts/export?host_id=host-hunt&minutes=60')
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_endpoint_timeline_records_investigation_session(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);

        DB::table('telemetry_events')->insert([
            'ts' => now(),
            'event_id' => 'timeline-event-1',
            'telemetry_type' => 'endpoint',
            'event_type' => 'connection_delta',
            'host_id' => 'host-timeline',
            'src_ip' => '10.0.0.10',
            'dst_ip' => '203.0.113.10',
            'dst_port' => 443,
            'protocol' => 'tcp',
            'process_name' => 'curl',
            'payload' => json_encode(['url' => 'https://example.test']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($analyst)
            ->get('/soc/endpoints/host-timeline?minutes=60&type=connection_delta')
            ->assertOk()
            ->assertSee('host-timeline')
            ->assertSee('connection_delta');

        $this->assertDatabaseHas('endpoint_investigation_sessions', [
            'host_id' => 'host-timeline',
            'created_by' => $analyst->email,
        ]);
    }

    public function test_forensic_collection_requires_workflow_permission_and_writes_audited_artifact(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $analyst = User::factory()->create(['role' => 'analyst']);

        DB::table('endpoint_agents')->insert([
            'agent_id' => 'agent-forensic',
            'host_fingerprint' => 'fp-forensic',
            'host_id' => 'host-forensic',
            'agent_version' => '0.2.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->post('/soc/forensics', [
                'agent_id' => 'agent-forensic',
                'host_id' => 'host-forensic',
                'collection_type' => 'endpoint-diagnostics',
            ])
            ->assertForbidden();

        $this->actingAs($analyst)
            ->post('/soc/forensics', [
                'agent_id' => 'agent-forensic',
                'host_id' => 'host-forensic',
                'collection_type' => 'endpoint-diagnostics',
            ])
            ->assertRedirect();

        $job = DB::table('forensic_collection_jobs')->where('agent_id', 'agent-forensic')->first();
        $this->assertNotNull($job);
        $this->assertSame('pending_approval', $job->status);

        $this->actingAs($analyst)
            ->post('/soc/forensics/'.$job->job_id.'/decision', ['decision' => 'approve'])
            ->assertRedirect();

        $completed = DB::table('forensic_collection_jobs')->where('job_id', $job->job_id)->first();
        $this->assertSame('completed', $completed->status);
        $this->assertNotEmpty($completed->artifact_sha256);
        $this->assertFileExists($completed->artifact_path);

        $this->assertDatabaseHas('security_audit_trails', [
            'actor' => $analyst->email,
            'action' => 'forensic.approve_collect',
            'target_id' => $job->job_id,
        ]);
    }
}
