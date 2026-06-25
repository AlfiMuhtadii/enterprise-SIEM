<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SocContainmentEnterpriseReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_containment_response_is_approval_gated_and_simulated(): void
    {
        $analyst  = User::factory()->create(['role' => 'analyst']);
        $approver = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($analyst)->post('/soc/responses', [
            'source_type' => 'incident',
            'source_id' => 'INC-CONTAIN-1',
            'agent_id' => 'agent-contain-1',
            'action_type' => 'isolate-host',
            'severity' => 'high',
            'reason' => 'critical incident containment drill',
        ])->assertRedirect();

        $response = DB::table('soc_response_workflows')->where('source_id', 'INC-CONTAIN-1')->first();
        $this->assertNotNull($response);
        $this->assertSame('pending_approval', $response->status);

        // Use a different user to approve — self-approval is blocked
        $this->actingAs($approver)->post('/soc/responses/'.$response->response_id.'/decision', [
            'decision' => 'approve',
        ])->assertRedirect();

        $this->assertDatabaseHas('soc_response_workflows', [
            'response_id' => $response->response_id,
            'status' => 'approved_simulated',
            'approved_by' => $approver->email,
        ]);
        $this->assertDatabaseHas('containment_simulations', [
            'response_id' => $response->response_id,
            'target_type' => 'host',
            'target_value' => 'agent-contain-1',
            'action_type' => 'isolate-host',
            'status' => 'approved_simulated',
        ]);
        $this->assertDatabaseHas('security_audit_trails', [
            'actor' => $approver->email,
            'action' => 'response.approve_containment_simulation',
            'target_type' => 'response',
            'target_id' => $response->response_id,
        ]);
        $this->assertDatabaseMissing('agent_commands', [
            'agent_id' => 'agent-contain-1',
            'command_type' => 'isolate-host',
        ]);
    }

    public function test_enterprise_readiness_commands_store_operational_reports(): void
    {
        $this->artisan('soc:env-validate local')->assertSuccessful();
        $this->artisan('soc:soak-test --events=250 --environment=local')->assertSuccessful();
        $this->artisan('soc:retention-cost --days=30 --environment=local')->assertSuccessful();

        $this->assertDatabaseHas('enterprise_validation_runs', [
            'run_type' => 'environment_validation',
            'environment' => 'local',
        ]);
        $this->assertDatabaseHas('enterprise_validation_runs', [
            'run_type' => 'soak_test',
            'environment' => 'local',
        ]);
        $this->assertDatabaseHas('retention_cost_reports', [
            'environment' => 'local',
            'period_days' => 30,
        ]);
    }
}
