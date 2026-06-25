<?php

namespace Tests\Feature;

use App\Exceptions\TenantContextMissingException;
use App\Exceptions\TenantSpoofAttemptException;
use App\Models\EndpointAgent;
use App\Models\User;
use App\Services\EndpointResponseCommandService;
use App\Services\TenantContextAuthority;
use App\Support\Rbac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENTERPRISE-039: RBAC & Audit Coverage Review
 *
 * Coverage:
 *  - RBAC permission inventory per role
 *  - Route/action permission enforcement
 *  - Self-approval guards (response workflow + endpoint command)
 *  - Audit event trail for privileged actions
 *  - Cross-tenant access boundary enforcement
 *  - Evidence-freeze self-approval block
 */
class RbacAuditCoverageTest extends TestCase
{
    use RefreshDatabase;

    // ── RBAC Permission Inventory ─────────────────────────────────────────────

    public function test_admin_holds_all_required_permissions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $required = [
            'rules.manage', 'rules.govern', 'response.approve',
            'dlq.review', 'shadow.soak.run', 'security.view',
            'audit.view', 'scenario.run', 'advisory.review',
        ];
        foreach ($required as $perm) {
            $this->assertTrue(Rbac::can($admin, $perm), "admin must hold {$perm}");
        }
    }

    public function test_viewer_holds_only_read_permissions(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $forbidden = [
            'rules.manage', 'rules.govern', 'response.approve', 'response.create',
            'dlq.review', 'shadow.soak.run', 'security.view',
            'scenario.run', 'advisory.review', 'agents.manage',
        ];
        foreach ($forbidden as $perm) {
            $this->assertFalse(Rbac::can($viewer, $perm), "viewer must NOT hold {$perm}");
        }
    }

    public function test_analyst_cannot_manage_rules(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->assertFalse(Rbac::can($analyst, 'rules.manage'));
        $this->assertFalse(Rbac::can($analyst, 'security.view'));
        $this->assertFalse(Rbac::can($analyst, 'shadow.soak.run'));
        $this->assertFalse(Rbac::can($analyst, 'dlq.review'));
    }

    public function test_analyst_can_approve_responses_and_execute_workflow(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->assertTrue(Rbac::can($analyst, 'response.approve'));
        $this->assertTrue(Rbac::can($analyst, 'response.create'));
        $this->assertTrue(Rbac::can($analyst, 'workflow.execute'));
        $this->assertTrue(Rbac::can($analyst, 'advisory.review'));
    }

    public function test_detection_engineer_can_review_dlq_and_run_shadow_soak(): void
    {
        $de = User::factory()->create(['role' => 'detection_engineer']);
        $this->assertTrue(Rbac::can($de, 'dlq.review'));
        $this->assertTrue(Rbac::can($de, 'shadow.soak.run'));
        $this->assertTrue(Rbac::can($de, 'rules.govern'));
        $this->assertFalse(Rbac::can($de, 'rules.manage'));
        $this->assertFalse(Rbac::can($de, 'response.approve'));
        $this->assertFalse(Rbac::can($de, 'security.view'));
    }

    public function test_scenario_operator_cannot_approve_responses_or_review_dlq(): void
    {
        $so = User::factory()->create(['role' => 'scenario_operator']);
        $this->assertFalse(Rbac::can($so, 'response.approve'));
        $this->assertFalse(Rbac::can($so, 'workflow.execute'));
        $this->assertFalse(Rbac::can($so, 'dlq.review'));
        $this->assertFalse(Rbac::can($so, 'shadow.soak.run'));
        $this->assertFalse(Rbac::can($so, 'rules.manage'));
    }

    public function test_unknown_role_falls_back_to_viewer(): void
    {
        // Rbac::role() maps any unrecognised role string to 'viewer' (lowest privilege).
        $user = User::factory()->create(['role' => 'intruder']);
        $this->assertTrue(Rbac::can($user, 'dashboard.view'),  'unknown role gets viewer read access');
        $this->assertFalse(Rbac::can($user, 'rules.manage'),   'unknown role cannot manage rules');
        $this->assertFalse(Rbac::can($user, 'response.approve'), 'unknown role cannot approve responses');
        $this->assertFalse(Rbac::can($user, 'dlq.review'),     'unknown role cannot review DLQ');
        $this->assertFalse(Rbac::can($user, 'shadow.soak.run'), 'unknown role cannot run shadow soak');
    }

    // ── Route Permission Matrix ───────────────────────────────────────────────

    public function test_viewer_cannot_post_scenario_run(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->post('/scenario/runs')->assertForbidden();
    }

    public function test_analyst_cannot_run_shadow_soak(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)->post('/shadow-soak')->assertForbidden();
    }

    public function test_analyst_cannot_post_dlq_record_review(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        // Analyst has dlq.view but NOT dlq.review
        $this->actingAs($analyst)->post('/dlq/records/dlq-test-001/review')->assertForbidden();
    }

    public function test_scenario_operator_cannot_post_response_decision(): void
    {
        $so = User::factory()->create(['role' => 'scenario_operator']);
        // soc:workflow.execute is required; scenario_operator does not have it
        $this->actingAs($so)
            ->post('/soc/responses/resp-000/decision', ['decision' => 'approve'])
            ->assertForbidden();
    }

    public function test_viewer_cannot_access_rules_management_route(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->get('/soc/rules')->assertForbidden();
    }

    // ── Self-Approval Guards ──────────────────────────────────────────────────

    public function test_response_workflow_self_approve_is_blocked(): void
    {
        $analyst    = User::factory()->create(['role' => 'analyst']);
        $responseId = 'resp-self-approve-039';
        DB::table('soc_response_workflows')->insert([
            'response_id'    => $responseId,
            'source_type'    => 'alert',
            'source_id'      => 'alert-001',
            'action_type'    => 'collect-now',
            'status'         => 'pending_approval',
            'recommended_by' => $analyst->email,
            'created_at'     => now()->format('Y-m-d H:i:sP'),
            'updated_at'     => now()->format('Y-m-d H:i:sP'),
        ]);

        $response = $this->actingAs($analyst)
            ->post("/soc/responses/{$responseId}/decision", ['decision' => 'approve']);

        $response->assertSessionHasErrors(['decision']);
        $this->assertDatabaseHas('soc_response_workflows', [
            'response_id' => $responseId,
            'status'      => 'pending_approval',
        ]);
    }

    public function test_response_workflow_self_reject_is_permitted(): void
    {
        // Recommender may withdraw (reject) their own recommendation
        $analyst    = User::factory()->create(['role' => 'analyst']);
        $responseId = 'resp-self-reject-039';
        DB::table('soc_response_workflows')->insert([
            'response_id'    => $responseId,
            'source_type'    => 'alert',
            'source_id'      => 'alert-001',
            'action_type'    => 'collect-now',
            'status'         => 'pending_approval',
            'recommended_by' => $analyst->email,
            'created_at'     => now()->format('Y-m-d H:i:sP'),
            'updated_at'     => now()->format('Y-m-d H:i:sP'),
        ]);

        $this->actingAs($analyst)
            ->post("/soc/responses/{$responseId}/decision", ['decision' => 'reject'])
            ->assertRedirect();

        $this->assertDatabaseHas('soc_response_workflows', [
            'response_id' => $responseId,
            'status'      => 'rejected',
        ]);
    }

    public function test_response_workflow_cross_user_approve_passes_self_approve_guard(): void
    {
        $recommender = User::factory()->create(['role' => 'analyst']);
        $approver    = User::factory()->create(['role' => 'analyst']);
        $responseId  = 'resp-cross-approve-039';
        DB::table('soc_response_workflows')->insert([
            'response_id'    => $responseId,
            'source_type'    => 'alert',
            'source_id'      => 'alert-001',
            'action_type'    => 'collect-now',
            'status'         => 'pending_approval',
            'recommended_by' => $recommender->email,
            'created_at'     => now()->format('Y-m-d H:i:sP'),
            'updated_at'     => now()->format('Y-m-d H:i:sP'),
        ]);

        // Different user — self-approval guard does NOT fire; no 'decision' error
        $resp = $this->actingAs($approver)
            ->post("/soc/responses/{$responseId}/decision", ['decision' => 'approve']);

        $resp->assertSessionDoesntHaveErrors(['decision']);
    }

    public function test_endpoint_command_self_approve_throws(): void
    {
        $user    = User::factory()->create(['role' => 'admin']);
        $agent   = EndpointAgent::factory()->create();
        $service = app(EndpointResponseCommandService::class);

        $command = $service->createCommand($agent, 'noop', [], $user->id);
        $service->submitForApproval($command, $user->id);
        $command->refresh();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Self-approval is blocked/');

        $service->approve($command, $user->id);
    }

    public function test_endpoint_command_cross_user_approve_succeeds(): void
    {
        $creator  = User::factory()->create(['role' => 'admin']);
        $approver = User::factory()->create(['role' => 'admin']);
        $agent    = EndpointAgent::factory()->create();
        $service  = app(EndpointResponseCommandService::class);

        $command = $service->createCommand($agent, 'noop', [], $creator->id);
        $service->submitForApproval($command, $creator->id);
        $command->refresh();

        $approved = $service->approve($command, $approver->id);
        $this->assertSame('approved', $approved->status);
    }

    public function test_endpoint_command_null_creator_can_be_approved_by_same_user(): void
    {
        // Commands created by system (created_by=null) may be approved by any user
        $approver = User::factory()->create(['role' => 'admin']);
        $agent    = EndpointAgent::factory()->create();
        $service  = app(EndpointResponseCommandService::class);

        $command = $service->createCommand($agent, 'noop', [], null);
        $service->submitForApproval($command, $approver->id);
        $command->refresh();

        $approved = $service->approve($command, $approver->id);
        $this->assertSame('approved', $approved->status);
    }

    // ── Audit Event Coverage ─────────────────────────────────────────────────

    public function test_response_recommendation_writes_audit_trail(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($analyst)->post('/soc/responses', [
            'source_type' => 'alert',
            'source_id'   => 'alert-audit-039',
            'action_type' => 'collect-now',
            'reason'      => 'high-severity alert',
        ])->assertRedirect();

        $this->assertDatabaseHas('security_audit_trails', [
            'actor'       => $analyst->email,
            'action'      => 'response.recommend',
            'target_type' => 'response',
        ]);
    }

    public function test_response_rejection_writes_audit_trail(): void
    {
        $recommender = User::factory()->create(['role' => 'analyst']);
        $approver    = User::factory()->create(['role' => 'analyst']);
        $responseId  = 'resp-audit-reject-039';
        DB::table('soc_response_workflows')->insert([
            'response_id'    => $responseId,
            'source_type'    => 'alert',
            'source_id'      => 'alert-001',
            'action_type'    => 'collect-now',
            'status'         => 'pending_approval',
            'recommended_by' => $recommender->email,
            'created_at'     => now()->format('Y-m-d H:i:sP'),
            'updated_at'     => now()->format('Y-m-d H:i:sP'),
        ]);

        $this->actingAs($approver)
            ->post("/soc/responses/{$responseId}/decision", ['decision' => 'reject'])
            ->assertRedirect();

        $this->assertDatabaseHas('security_audit_trails', [
            'actor'       => $approver->email,
            'action'      => 'response.reject',
            'target_type' => 'response',
            'target_id'   => $responseId,
        ]);
    }

    // ── Cross-Tenant Access Boundary ─────────────────────────────────────────

    public function test_tenant_spoof_attempt_throws_exception(): void
    {
        $user      = User::factory()->create(['role' => 'analyst']);
        $authority = app(TenantContextAuthority::class);
        $authority->grantMembership($user->id, 'tenant-alpha', $user->id);

        $request = Request::create('/advisory/findings', 'GET');
        $request->headers->set('X-Tenant-ID', 'tenant-beta');

        $this->expectException(TenantSpoofAttemptException::class);
        $authority->validateAndResolve($request, $user, requireTenantContext: true);
    }

    public function test_strict_mode_missing_header_throws_exception(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $user      = User::factory()->create(['role' => 'analyst']);
        $authority = app(TenantContextAuthority::class);

        $request = Request::create('/advisory/findings', 'GET');
        // No X-Tenant-ID header

        $this->expectException(TenantContextMissingException::class);
        $authority->validateAndResolve($request, $user, requireTenantContext: true);
    }

    public function test_admin_bypasses_tenant_membership_enforcement(): void
    {
        $admin     = User::factory()->create(['role' => 'admin']);
        $authority = app(TenantContextAuthority::class);

        $request = Request::create('/advisory/findings', 'GET');
        $request->headers->set('X-Tenant-ID', 'tenant-any');

        // Admin bypasses membership check — no exception; returns the header value
        $resolved = $authority->validateAndResolve($request, $admin, requireTenantContext: true);
        $this->assertSame('tenant-any', $resolved);
    }

    public function test_non_admin_with_matching_membership_resolves_tenant(): void
    {
        $user      = User::factory()->create(['role' => 'analyst']);
        $authority = app(TenantContextAuthority::class);
        $authority->grantMembership($user->id, 'tenant-gamma', $user->id);

        $request = Request::create('/advisory/findings', 'GET');
        $request->headers->set('X-Tenant-ID', 'tenant-gamma');

        $resolved = $authority->validateAndResolve($request, $user, requireTenantContext: true);
        $this->assertSame('tenant-gamma', $resolved);
    }

    // ── Evidence-Freeze Authorization ─────────────────────────────────────────

    public function test_pilot_evidence_freeze_blocks_self_approval(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'    => 'operator@example.com',
            '--approved-by' => 'operator@example.com',
        ])->assertExitCode(1);
    }

    public function test_pilot_evidence_freeze_allows_cross_user_dry_run(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'    => 'operator@example.com',
            '--approved-by' => 'supervisor@example.com',
            '--dry-run'     => true,
        ])->assertExitCode(0);
    }
}
