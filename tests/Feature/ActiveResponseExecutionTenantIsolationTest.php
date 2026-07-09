<?php

namespace Tests\Feature;

use App\Models\ResponseExecution;
use App\Models\User;
use App\Services\ActiveResponseExecutionService;
use App\Services\TenantContextAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENT-TENANCY-RESPONSE-EXECUTION — response_executions and its 3 append-only
 * child tables (events/rollbacks/simulations) had no tenant_id at all.
 * Active response controls highly privileged containment operations
 * (session revocation, host isolation, account disabling); without a
 * tenant boundary a compromised tenant operator could view, simulate,
 * approve, or execute response plans targeting another tenant's resources.
 */
class ActiveResponseExecutionTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): ActiveResponseExecutionService
    {
        return app(ActiveResponseExecutionService::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeExec(User $creator, ?string $tenantId, string $action = ResponseExecution::ACTION_REVOKE_SESSION): ResponseExecution
    {
        return $this->svc()->createExecution([
            'action_type'        => $action,
            'target_entity_type' => 'user',
            'target_entity_key'  => 'tenant-test-user@example.com',
            'rationale'          => 'Tenant isolation test rationale',
        ], $creator, $tenantId);
    }

    public function test_create_execution_persists_tenant_id(): void
    {
        $exec = $this->makeExec($this->admin(), 'tenant-a');

        $this->assertSame('tenant-a', $exec->tenant_id);
        $this->assertDatabaseHas('response_executions', ['execution_id' => $exec->execution_id, 'tenant_id' => 'tenant-a']);
    }

    public function test_appended_event_inherits_execution_tenant_id(): void
    {
        $exec = $this->makeExec($this->admin(), 'tenant-a');

        $this->assertDatabaseHas('response_execution_events', ['execution_id' => $exec->id, 'tenant_id' => 'tenant-a']);
    }

    public function test_simulation_inherits_execution_tenant_id(): void
    {
        $creator = $this->admin();
        $approver = $this->admin();
        $exec = $this->makeExec($creator, 'tenant-a');
        $this->svc()->submitForApproval($exec, $creator);
        $this->svc()->approve($exec->fresh(), $approver, 'ok');
        $sim = $this->svc()->runSimulation($exec->fresh(), $approver);

        $this->assertSame('tenant-a', $sim->tenant_id);
    }

    public function test_rollback_inherits_execution_tenant_id(): void
    {
        $creator = $this->admin();
        $approver = $this->admin();
        $exec = $this->makeExec($creator, 'tenant-a', ResponseExecution::ACTION_REVOKE_SESSION);
        $this->svc()->submitForApproval($exec, $creator);
        $this->svc()->approve($exec->fresh(), $approver, 'ok');
        $exec = $exec->fresh();
        $this->svc()->runSimulation($exec, $approver);
        $exec = $exec->fresh();
        $this->svc()->requestExecution($exec, $approver);
        $exec = $exec->fresh();
        $this->svc()->executeAction($exec, $approver, 'confirmed action taken');
        $exec = $exec->fresh();

        $rollback = $this->svc()->initiateRollback($exec, $approver);

        $this->assertSame('tenant-a', $rollback->tenant_id);
    }

    public function test_get_execution_returns_null_for_other_tenant(): void
    {
        $exec = $this->makeExec($this->admin(), 'tenant-b');

        $result = $this->svc()->getExecution($exec->execution_id, 'tenant-a');

        $this->assertNull($result);
    }

    public function test_get_execution_returns_result_for_same_tenant(): void
    {
        $exec = $this->makeExec($this->admin(), 'tenant-a');

        $result = $this->svc()->getExecution($exec->execution_id, 'tenant-a');

        $this->assertNotNull($result);
        $this->assertSame($exec->execution_id, $result->execution_id);
    }

    public function test_get_execution_null_tenant_id_sees_all_legacy_and_scoped(): void
    {
        $this->makeExec($this->admin(), 'tenant-a');
        $this->makeExec($this->admin(), null);

        $this->assertSame(2, DB::table('response_executions')->count());
    }

    public function test_get_recent_executions_scoped_by_tenant(): void
    {
        $this->makeExec($this->admin(), 'tenant-a');
        $this->makeExec($this->admin(), 'tenant-b');

        $resultsA = $this->svc()->getRecentExecutions(30, 'tenant-a');

        $this->assertCount(1, $resultsA);
        $this->assertSame('tenant-a', $resultsA->first()->tenant_id);
    }

    public function test_get_pending_approvals_scoped_by_tenant(): void
    {
        $execA = $this->makeExec($this->admin(), 'tenant-a');
        $this->svc()->submitForApproval($execA, $this->admin());
        $execB = $this->makeExec($this->admin(), 'tenant-b');
        $this->svc()->submitForApproval($execB, $this->admin());

        $pendingA = $this->svc()->getPendingApprovals('tenant-a');

        $this->assertCount(1, $pendingA);
        $this->assertSame($execA->execution_id, $pendingA->first()->execution_id);
    }

    public function test_get_rollback_candidates_scoped_by_tenant(): void
    {
        $creator = $this->admin();
        $approver = $this->admin();
        $execA = $this->makeExec($creator, 'tenant-a');
        $this->svc()->submitForApproval($execA, $creator);
        $this->svc()->approve($execA->fresh(), $approver, 'ok');
        $execA = $execA->fresh();
        $this->svc()->runSimulation($execA, $approver);
        $execA = $execA->fresh();
        $this->svc()->requestExecution($execA, $approver);
        $execA = $execA->fresh();
        $this->svc()->executeAction($execA, $approver, 'confirmed action taken');

        $execB = $this->makeExec($creator, 'tenant-b');
        $this->svc()->submitForApproval($execB, $creator);
        $this->svc()->approve($execB->fresh(), $approver, 'ok');
        $execB = $execB->fresh();
        $this->svc()->runSimulation($execB, $approver);
        $execB = $execB->fresh();
        $this->svc()->requestExecution($execB, $approver);
        $execB = $execB->fresh();
        $this->svc()->executeAction($execB, $approver, 'confirmed action taken');

        $candidatesA = $this->svc()->getRollbackCandidates('tenant-a');

        $this->assertCount(1, $candidatesA);
    }

    public function test_blast_radius_entity_lookup_prefers_matching_tenant_over_other_tenant(): void
    {
        // Two entities share the same entity_key across tenants — after
        // ENT-TENANCY-ENTITY-GRAPH these no longer merge, so blast radius
        // must resolve the correct tenant's entity, not an arbitrary one.
        $graph = app(\App\Services\EntityGraphService::class);
        $graph->upsertEntity('user', 'shared-target@example.com', '', null, null, [], 'tenant-a');
        $entityIdB = $graph->upsertEntity('user', 'shared-target@example.com', '', null, null, [], 'tenant-b');
        // Give tenant-b's entity extra observations so blast radius would
        // differ measurably if the wrong entity were picked.
        DB::table('entities')->where('id', $entityIdB)->update(['observation_count' => 40]);

        $radiusA = $this->svc()->calculateBlastRadius(ResponseExecution::ACTION_REVOKE_SESSION, 'shared-target@example.com', 'tenant-a');
        $radiusB = $this->svc()->calculateBlastRadius(ResponseExecution::ACTION_REVOKE_SESSION, 'shared-target@example.com', 'tenant-b');

        $this->assertLessThan($radiusB, $radiusA, 'tenant-a\'s low-observation entity should yield a smaller blast radius than tenant-b\'s high-observation entity');
    }

    public function test_http_show_returns_404_for_execution_in_other_tenant(): void
    {
        $exec = $this->makeExec($this->admin(), 'tenant-b');
        $viewer = $this->admin();
        app(TenantContextAuthority::class)->grantMembership($viewer->id, 'tenant-a', $viewer->id);

        $response = $this->actingAs($viewer)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->get(route('active-response.show', $exec->execution_id));

        $response->assertStatus(404);
    }

    public function test_http_show_returns_200_for_execution_in_same_tenant(): void
    {
        $exec = $this->makeExec($this->admin(), 'tenant-a');
        $viewer = $this->admin();
        app(TenantContextAuthority::class)->grantMembership($viewer->id, 'tenant-a', $viewer->id);

        $response = $this->actingAs($viewer)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->get(route('active-response.show', $exec->execution_id));

        $response->assertOk();
    }

    public function test_api_get_execution_returns_404_for_execution_in_other_tenant(): void
    {
        $exec = $this->makeExec($this->admin(), 'tenant-b');
        $viewer = $this->admin();
        app(TenantContextAuthority::class)->grantMembership($viewer->id, 'tenant-a', $viewer->id);

        $response = $this->actingAs($viewer)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->getJson(route('api.active-response.show', $exec->execution_id));

        $response->assertStatus(404);
    }
}
