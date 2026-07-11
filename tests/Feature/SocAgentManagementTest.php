<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SocAgentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_agent_policy_and_queue_command(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        DB::table('endpoint_agents')->insert([
            'agent_id' => 'agent-test-management',
            'host_fingerprint' => 'fp-management',
            'host_id' => 'host-management',
            'agent_version' => '0.1.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->get('/soc/agents')->assertOk();

        $this->actingAs($admin)->post('/soc/agents/policies', [
            'policy_id' => 'policy-management',
            'name' => 'Policy Management',
            'collection_interval_seconds' => 45,
            'max_batch_size' => 200,
            'collect_process' => '1',
            'collect_network' => '1',
            'is_default' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('agent_policies', [
            'policy_id' => 'policy-management',
            'name' => 'Policy Management',
            'is_default' => true,
        ]);

        $this->actingAs($admin)->post('/soc/agents/agent-test-management/policy', [
            'policy_id' => 'policy-management',
        ])->assertRedirect();

        $this->assertDatabaseHas('endpoint_agents', [
            'agent_id' => 'agent-test-management',
            'policy_id' => 'policy-management',
        ]);

        $this->actingAs($admin)->post('/soc/agents/agent-test-management/commands', [
            'command_type' => 'collect-now',
        ])->assertRedirect();

        // RESP-1: command now routed through EndpointResponseCommandService (endpoint_response_commands).
        // collect-now maps to collect_diagnostics; initial status is draft.
        $this->assertDatabaseHas('endpoint_response_commands', [
            'command_type' => 'collect_diagnostics',
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('security_audit_trails', [
            'actor' => $admin->email,
            'action' => 'agent.command.queue',
            'target_id' => 'agent-test-management',
        ]);
    }

    public function test_viewer_cannot_manage_agents(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->get('/soc/agents')->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Tenant isolation
    // -----------------------------------------------------------------------

    private function insertAgent(string $agentId, ?string $tenantId): void
    {
        DB::table('endpoint_agents')->insert([
            'agent_id' => $agentId,
            'host_fingerprint' => 'fp-'.$agentId,
            'host_id' => 'host-'.$agentId,
            'agent_version' => '0.1.0',
            'status' => 'online',
            'tenant_id' => $tenantId,
            'last_seen_at' => now(),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertTamperAlert(string $alertId, ?string $tenantId): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => $alertId,
            'alert_fingerprint' => 'fp-'.$alertId,
            'dedup_group' => 'g|'.$alertId,
            'is_suppressed' => false,
            'detected_at' => now(),
            'alert_type' => 'AGENT_STALE_OR_STOPPED',
            'detector_name' => 'AGENT_STALE_OR_STOPPED',
            'detector_version' => 'v1',
            'severity' => 'medium',
            'actor_key' => 'host-'.$alertId,
            'score' => 0.5,
            'tenant_id' => $tenantId,
            'evidence' => json_encode([]),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_index_only_shows_agents_and_tamper_alerts_for_the_requesting_tenant(): void
    {
        $this->insertAgent('agent-tenant-a', 'tenant-a');
        $this->insertAgent('agent-tenant-b', 'tenant-b');
        $this->insertTamperAlert('alert-tamper-a', 'tenant-a');
        $this->insertTamperAlert('alert-tamper-b', 'tenant-b');

        $analyst = User::factory()->create(['role' => 'analyst']);

        $response = $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->get('/soc/agents');

        $response->assertOk();
        $response->assertSee('agent-tenant-a');
        $response->assertDontSee('agent-tenant-b');
        $response->assertSee('alert-tamper-a');
        $response->assertDontSee('alert-tamper-b');
    }

    public function test_assign_policy_blocks_cross_tenant_agent(): void
    {
        $this->insertAgent('agent-cross-a', 'tenant-a');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-b'])
            ->post('/soc/agents/agent-cross-a/policy', ['policy_id' => 'any-policy'])
            ->assertForbidden();

        $this->assertDatabaseHas('endpoint_agents', [
            'agent_id' => 'agent-cross-a',
            'policy_id' => null,
        ]);
    }

    public function test_assign_policy_allows_matching_tenant(): void
    {
        $this->insertAgent('agent-match-a', 'tenant-a');
        DB::table('agent_policies')->insert([
            'policy_id' => 'policy-match',
            'name' => 'Policy Match',
            'version' => 1,
            'is_default' => false,
            'collection_interval_seconds' => 60,
            'max_batch_size' => 100,
            'enabled_collectors' => json_encode([]),
            'retry_policy' => json_encode([]),
            'telemetry_categories' => json_encode([]),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->post('/soc/agents/agent-match-a/policy', ['policy_id' => 'policy-match'])
            ->assertRedirect();

        $this->assertDatabaseHas('endpoint_agents', [
            'agent_id' => 'agent-match-a',
            'policy_id' => 'policy-match',
        ]);
    }

    public function test_queue_command_blocks_cross_tenant_agent(): void
    {
        $this->insertAgent('agent-cmd-cross-a', 'tenant-a');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-b'])
            ->post('/soc/agents/agent-cmd-cross-a/commands', ['command_type' => 'collect-now'])
            ->assertForbidden();

        $this->assertSame(0, DB::table('endpoint_response_commands')->count());
    }
}
