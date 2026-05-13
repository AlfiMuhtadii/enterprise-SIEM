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

        $this->assertDatabaseHas('agent_commands', [
            'agent_id' => 'agent-test-management',
            'command_type' => 'collect-now',
            'status' => 'queued',
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
}
