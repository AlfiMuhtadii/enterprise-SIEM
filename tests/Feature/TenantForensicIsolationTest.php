<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantBoundaryService;
use App\Services\TenantContextAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TENANT-FORENSIC-ISOLATION: forensic_collection_jobs had no tenant_id at
 * all -- request()/decide()/buildArtifact() ran completely unscoped, and
 * the nested security_alerts/endpoint_agents queries inside buildArtifact()
 * could leak another tenant's alerts/agent metadata into a forensic
 * bundle via a shared hostname string. Also closes the same listing gap
 * in SocAgentController::index()'s forensicJobs panel and
 * SocDashboardController's forensic_pending/forensic_completed_24h counts.
 */
class TenantForensicIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_forensic_collection_jobs_has_a_tenant_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('forensic_collection_jobs', 'tenant_id'));
    }

    public function test_forensic_collection_jobs_is_registered_isolated_and_mutable(): void
    {
        $this->assertContains('forensic_collection_jobs', TenantBoundaryService::ISOLATED_TABLES);
        $this->assertContains('forensic_collection_jobs', TenantBoundaryService::MUTABLE_TABLES);
    }

    private function actingAsTenant(string $tenantId): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        app(TenantContextAuthority::class)->grantMembership($user->id, $tenantId, $user->id);

        return $user;
    }

    private function seedAgent(string $agentId, string $tenantId): void
    {
        DB::table('endpoint_agents')->insert([
            'agent_id' => $agentId,
            'agent_secret' => encrypt('secret'),
            'tenant_id' => $tenantId,
            'host_fingerprint' => 'fp-'.$agentId,
            'host_id' => 'host-'.$agentId,
            'os_family' => 'linux',
            'agent_version' => '1.0.0',
            'status' => 'online',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_request_stores_tenant_id_from_the_requesting_analyst(): void
    {
        $user = $this->actingAsTenant('tenant-a');

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->post('/soc/forensics', ['host_id' => 'host-x', 'collection_type' => 'telemetry-snapshot'])
            ->assertRedirect();

        $this->assertDatabaseHas('forensic_collection_jobs', [
            'host_id' => 'host-x',
            'tenant_id' => 'tenant-a',
        ]);
    }

    public function test_request_is_rejected_when_targeted_agent_belongs_to_another_tenant(): void
    {
        $this->seedAgent('agent-other-tenant', 'tenant-b');
        $user = $this->actingAsTenant('tenant-a');

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->post('/soc/forensics', ['agent_id' => 'agent-other-tenant', 'collection_type' => 'telemetry-snapshot'])
            ->assertForbidden();

        $this->assertDatabaseMissing('forensic_collection_jobs', ['agent_id' => 'agent-other-tenant']);
    }

    public function test_decide_is_rejected_for_a_job_belonging_to_another_tenant(): void
    {
        DB::table('forensic_collection_jobs')->insert([
            'job_id' => 'forensic-other-tenant-job',
            'tenant_id' => 'tenant-b',
            'host_id' => 'host-y',
            'collection_type' => 'telemetry-snapshot',
            'status' => 'pending_approval',
            'requested_by' => 'someone@tenant-b.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = $this->actingAsTenant('tenant-a');

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->post('/soc/forensics/forensic-other-tenant-job/decision', ['decision' => 'approve'])
            ->assertForbidden();

        $this->assertDatabaseHas('forensic_collection_jobs', [
            'job_id' => 'forensic-other-tenant-job',
            'status' => 'pending_approval',
        ]);
    }

    public function test_approved_artifact_does_not_leak_another_tenants_alerts_or_agent(): void
    {
        $host = 'host-forensic-artifact-test';
        $this->seedAgent('agent-artifact-own', 'tenant-c');
        $this->seedAgent('agent-artifact-other', 'tenant-d');

        DB::table('security_alerts')->insert([
            'alert_id' => 'alert-own',
            'detected_at' => now(),
            'alert_type' => 'TEST',
            'detector_name' => 'TEST',
            'detector_version' => 'v1',
            'severity' => 'high',
            'tenant_id' => 'tenant-c',
            'score' => 0.9,
            'evidence' => json_encode(['host_id' => $host, 'marker' => 'own_tenant_alert_marker']),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('security_alerts')->insert([
            'alert_id' => 'alert-other',
            'detected_at' => now(),
            'alert_type' => 'TEST',
            'detector_name' => 'TEST',
            'detector_version' => 'v1',
            'severity' => 'high',
            'tenant_id' => 'tenant-d',
            'score' => 0.9,
            'evidence' => json_encode(['host_id' => $host, 'marker' => 'other_tenant_alert_marker']),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('forensic_collection_jobs')->insert([
            'job_id' => 'forensic-artifact-test-job',
            'tenant_id' => 'tenant-c',
            'agent_id' => 'agent-artifact-own',
            'host_id' => $host,
            'collection_type' => 'telemetry-snapshot',
            'status' => 'pending_approval',
            'requested_by' => 'analyst@tenant-c.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = $this->actingAsTenant('tenant-c');

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => 'tenant-c'])
            ->post('/soc/forensics/forensic-artifact-test-job/decision', ['decision' => 'approve'])
            ->assertRedirect();

        $artifactPath = storage_path('app/forensics/forensic-artifact-test-job.json');
        $this->assertFileExists($artifactPath);
        $artifact = json_decode(file_get_contents($artifactPath), true);

        $alertMarkers = array_column($artifact['alerts'], 'evidence');
        $alertMarkersJoined = implode(' ', $alertMarkers);
        $this->assertStringContainsString('own_tenant_alert_marker', $alertMarkersJoined);
        $this->assertStringNotContainsString('other_tenant_alert_marker', $alertMarkersJoined);
        $this->assertSame('agent-artifact-own', $artifact['agent']['agent_id']);

        @unlink($artifactPath);
        @unlink(storage_path('app/forensics/forensic-artifact-test-job.zip'));
    }

    public function test_agent_fleet_page_forensic_jobs_panel_is_scoped_to_own_tenant(): void
    {
        DB::table('forensic_collection_jobs')->insert([
            'job_id' => 'forensic-fleet-own',
            'tenant_id' => 'tenant-e',
            'host_id' => 'host-fleet-own-marker',
            'collection_type' => 'telemetry-snapshot',
            'status' => 'pending_approval',
            'requested_by' => 'analyst@tenant-e.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('forensic_collection_jobs')->insert([
            'job_id' => 'forensic-fleet-other',
            'tenant_id' => 'tenant-f',
            'host_id' => 'host-fleet-other-marker',
            'collection_type' => 'telemetry-snapshot',
            'status' => 'pending_approval',
            'requested_by' => 'analyst@tenant-f.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = $this->actingAsTenant('tenant-e');

        $response = $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => 'tenant-e'])
            ->get('/soc/agents');

        $response->assertOk();
        $response->assertSee('host-fleet-own-marker');
        $response->assertDontSee('host-fleet-other-marker');
    }
}
