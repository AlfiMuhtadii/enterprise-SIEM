<?php

namespace Tests\Feature;

use App\Models\SoarApprovalRequest;
use App\Models\SoarExecutionAudit;
use App\Models\SoarExecutionPlan;
use App\Models\SoarExecutionResult;
use App\Models\SoarExecutionStep;
use App\Models\SoarPlaybook;
use App\Models\SoarPlaybookVersion;
use App\Models\SoarRollbackPlan;
use App\Models\SoarSimulationResult;
use App\Models\User;
use App\Services\EntityRiskScoringService;
use App\Services\SoarOrchestrationService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SOAR Governance & Response Orchestration Phase 1 — Feature Tests.
 *
 * Hard safety assertions (MUST remain green):
 *   - No isolateHost
 *   - No quarantineHost
 *   - No executeShell
 *   - No killProcess
 *   - No autoRemediate
 *   - No autonomous execution
 *   - No approval bypass
 *   - Creator cannot self-approve
 *   - Simulation required before execution
 *   - Simulation-only actions never execute live
 *   - All append-only tables enforce immutability
 */
class SoarOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    private SoarOrchestrationService $svc;
    private ThreatHuntingService     $hunting;
    private User                     $user;
    private User                     $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc      = app(SoarOrchestrationService::class);
        $this->hunting  = app(ThreatHuntingService::class);
        $this->user     = User::factory()->create();
        $this->approver = User::factory()->create();
    }

    // =========================================================================
    // Schema — new tables exist
    // =========================================================================

    public function test_soar_playbooks_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('soar_playbooks'));
    }

    public function test_soar_playbook_versions_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('soar_playbook_versions'));
    }

    public function test_soar_execution_plans_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('soar_execution_plans'));
    }

    public function test_soar_execution_steps_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('soar_execution_steps'));
    }

    public function test_soar_execution_results_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('soar_execution_results'));
    }

    public function test_soar_approval_requests_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('soar_approval_requests'));
    }

    public function test_soar_rollback_plans_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('soar_rollback_plans'));
    }

    public function test_soar_execution_audit_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('soar_execution_audit'));
    }

    public function test_soar_simulation_results_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('soar_simulation_results'));
    }

    // =========================================================================
    // Playbook lifecycle
    // =========================================================================

    public function test_create_playbook_returns_valid_playbook(): void
    {
        $pb = $this->svc->createPlaybook(
            'Phishing Response',
            ['notify_analyst', 'create_incident', 'simulate_credential_revocation'],
            createdBy: $this->user->id
        );

        $this->assertInstanceOf(SoarPlaybook::class, $pb);
        $this->assertStringStartsWith('spb-', $pb->playbook_id);
        $this->assertSame('draft', $pb->status);
        $this->assertTrue($pb->simulation_required);
        $this->assertTrue($pb->dual_approval_required);
        $this->assertDatabaseHas('soar_playbooks', ['playbook_id' => $pb->playbook_id]);
    }

    public function test_create_playbook_auto_snapshots_version(): void
    {
        $pb = $this->svc->createPlaybook('Test Playbook');

        $versions = $this->svc->getVersionHistory($pb->playbook_id);
        $this->assertCount(1, $versions);
        $this->assertStringStartsWith('spv-', $versions->first()->version_id);
        $this->assertNotNull($versions->first()->version_hash);
    }

    public function test_activate_playbook_changes_status(): void
    {
        $pb = $this->svc->createPlaybook('Test');
        $this->svc->activatePlaybook($pb);
        $pb->refresh();

        $this->assertSame('active', $pb->status);
    }

    public function test_deprecate_playbook_changes_status(): void
    {
        $pb = $this->svc->createPlaybook('Test');
        $this->svc->deprecatePlaybook($pb);
        $pb->refresh();

        $this->assertSame('deprecated', $pb->status);
    }

    public function test_playbook_version_is_append_only(): void
    {
        $pb  = $this->svc->createPlaybook('Test');
        $ver = $this->svc->getVersionHistory($pb->playbook_id)->first();

        $this->expectException(\LogicException::class);
        $ver->version = '2.0.0';
        $ver->save();
    }

    public function test_playbook_version_hash_is_deterministic(): void
    {
        $pb    = $this->svc->createPlaybook('Test');
        $hash1 = SoarPlaybookVersion::hashPlaybook($pb);
        $hash2 = SoarPlaybookVersion::hashPlaybook($pb);

        $this->assertSame($hash1, $hash2);
        $this->assertSame(64, strlen($hash1));
    }

    public function test_playbook_rejects_unknown_action(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->createPlaybook('Bad Playbook', ['isolate_host_live_no_sim']);
    }

    // =========================================================================
    // Execution plan lifecycle
    // =========================================================================

    public function test_create_execution_plan_stores_record(): void
    {
        $pb   = $this->svc->createPlaybook('Test');
        $plan = $this->svc->createExecutionPlan(
            'Respond to phishing',
            playbookId: $pb->playbook_id,
            targetEntityId: 'user@example.com',
            createdBy: $this->user->id
        );

        $this->assertInstanceOf(SoarExecutionPlan::class, $plan);
        $this->assertStringStartsWith('sep-', $plan->plan_id);
        $this->assertSame('draft', $plan->status);
        $this->assertFalse($plan->simulation_completed);
    }

    public function test_add_step_to_plan_creates_step(): void
    {
        $plan = $this->svc->createExecutionPlan('Test plan');
        $step = $this->svc->addStepToplan($plan, 'Notify analyst', 'notify_analyst', 1);

        $this->assertInstanceOf(SoarExecutionStep::class, $step);
        $this->assertStringStartsWith('ses-', $step->step_id);
        $this->assertSame('pending', $step->status);
        $this->assertFalse($step->is_simulation_only);
    }

    public function test_simulation_only_step_is_flagged(): void
    {
        $plan = $this->svc->createExecutionPlan('Test plan');
        $step = $this->svc->addStepToplan($plan, 'Simulate isolation', 'simulate_endpoint_isolation', 1);

        $this->assertTrue($step->is_simulation_only);
    }

    public function test_add_step_rejects_unknown_action(): void
    {
        $plan = $this->svc->createExecutionPlan('Test plan');

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->addStepToplan($plan, 'Kill process', 'kill_process', 1);
    }

    // =========================================================================
    // Simulation-first execution
    // =========================================================================

    public function test_run_simulation_produces_result(): void
    {
        $plan = $this->svc->createExecutionPlan('Sim test', targetEntityId: 'user@example.com');
        $this->svc->addStepToplan($plan, 'Notify', 'notify_analyst', 1);
        $this->svc->addStepToplan($plan, 'Simulate block', 'simulate_firewall_block', 2);

        $result = $this->svc->runSimulation($plan, $this->user->id);

        $this->assertInstanceOf(SoarSimulationResult::class, $result);
        $this->assertStringStartsWith('ssr-', $result->sim_result_id);
        $this->assertTrue($result->is_advisory);
        $this->assertGreaterThan(0.0, $result->blast_radius_score);
        $this->assertNotNull($result->step_previews);
    }

    public function test_simulation_marks_plan_as_simulated(): void
    {
        $plan = $this->svc->createExecutionPlan('Sim test');
        $this->svc->addStepToplan($plan, 'Notify', 'notify_analyst', 1);
        $this->svc->runSimulation($plan, $this->user->id);
        $plan->refresh();

        $this->assertTrue($plan->simulation_completed);
        $this->assertSame('simulated', $plan->status);
    }

    public function test_simulation_is_always_advisory(): void
    {
        $plan = $this->svc->createExecutionPlan('Sim test');
        $this->svc->addStepToplan($plan, 'Notify', 'notify_analyst', 1);

        $result = $this->svc->runSimulation($plan);
        $this->assertTrue($result->is_advisory);
    }

    public function test_simulation_result_is_append_only(): void
    {
        $plan = $this->svc->createExecutionPlan('Sim test');
        $this->svc->addStepToplan($plan, 'Notify', 'notify_analyst', 1);
        $result = $this->svc->runSimulation($plan);

        $this->expectException(\LogicException::class);
        $result->blast_radius_score = 99.0;
        $result->save();
    }

    public function test_simulation_contains_warnings_for_simulation_only_actions(): void
    {
        $plan = $this->svc->createExecutionPlan('Sim test');
        $this->svc->addStepToplan($plan, 'Simulate isolation', 'simulate_endpoint_isolation', 1);

        $result = $this->svc->runSimulation($plan);
        $this->assertNotNull($result->warnings);
        $this->assertNotEmpty($result->warnings);
    }

    public function test_simulation_blast_radius_is_low_for_advisory_actions_only(): void
    {
        $plan = $this->svc->createExecutionPlan('Low risk plan');
        $this->svc->addStepToplan($plan, 'Notify', 'notify_analyst', 1);
        $this->svc->addStepToplan($plan, 'Create ticket', 'create_ticket', 2);

        $result = $this->svc->runSimulation($plan);
        // advisory-only actions have minimal blast radius (< 0.1)
        $this->assertLessThan(0.1, $result->blast_radius_score);
        $this->assertTrue($result->rollback_ready);
    }

    // =========================================================================
    // Approval governance
    // =========================================================================

    public function test_request_approval_requires_simulation_complete(): void
    {
        $plan = $this->svc->createExecutionPlan('Test plan');

        $this->expectException(\LogicException::class);
        $this->svc->requestApproval($plan, $this->user->id);
    }

    public function test_request_approval_creates_approval_record(): void
    {
        $plan = $this->svc->createExecutionPlan('Test plan');
        $this->svc->addStepToplan($plan, 'Notify', 'notify_analyst', 1);
        $this->svc->runSimulation($plan);

        $req = $this->svc->requestApproval($plan, $this->user->id, rationale: 'Phishing confirmed');
        $this->assertStringStartsWith('sar-', $req->approval_id);
        $this->assertSame('pending', $req->decision);

        $plan->refresh();
        $this->assertSame('approval_pending', $plan->status);
    }

    public function test_creator_cannot_self_approve(): void
    {
        $plan = $this->svc->createExecutionPlan('Test plan', createdBy: $this->user->id);
        $this->svc->addStepToplan($plan, 'Notify', 'notify_analyst', 1);
        $this->svc->runSimulation($plan);

        // Must manually set created_by for the guard to work
        DB::table('soar_execution_plans')->where('plan_id', $plan->plan_id)->update(['created_by' => $this->user->id]);
        $plan->refresh();

        $this->expectException(\LogicException::class);
        $this->svc->grantApproval($plan, $this->user->id, 'self approve attempt');
    }

    public function test_grant_approval_records_decision(): void
    {
        $plan = $this->svc->createExecutionPlan('Test plan', createdBy: $this->user->id);
        $this->svc->addStepToplan($plan, 'Notify', 'notify_analyst', 1);
        $this->svc->runSimulation($plan);
        $this->svc->requestApproval($plan, $this->user->id);

        $req = $this->svc->grantApproval($plan, $this->approver->id, 'Looks good');
        $this->assertSame('approved', $req->decision);
        $this->assertNotNull($req->decided_at);
    }

    public function test_reject_approval_sets_plan_to_cancelled(): void
    {
        $plan = $this->svc->createExecutionPlan('Test plan', createdBy: $this->user->id);
        $this->svc->addStepToplan($plan, 'Notify', 'notify_analyst', 1);
        $this->svc->runSimulation($plan);
        $this->svc->requestApproval($plan, $this->user->id);

        $this->svc->rejectApproval($plan, $this->approver->id, 'Not authorized');
        $plan->refresh();

        $this->assertSame('cancelled', $plan->status);
    }

    public function test_approval_request_is_append_only(): void
    {
        $plan = $this->svc->createExecutionPlan('Test plan');
        $this->svc->addStepToplan($plan, 'Notify', 'notify_analyst', 1);
        $this->svc->runSimulation($plan);
        $req = $this->svc->requestApproval($plan, $this->user->id);

        $this->expectException(\LogicException::class);
        $req->decision = 'approved';
        $req->save();
    }

    // =========================================================================
    // Rollback plan management
    // =========================================================================

    public function test_create_rollback_plan_stores_record(): void
    {
        $plan = $this->svc->createExecutionPlan('Test plan');
        $rb   = $this->svc->createRollbackPlan(
            $plan,
            [['action' => 'revoke_token', 'target' => 'user@example.com']],
            'advisory',
            createdBy: $this->user->id
        );

        $this->assertInstanceOf(SoarRollbackPlan::class, $rb);
        $this->assertStringStartsWith('srp-', $rb->rollback_id);
        $this->assertSame('not_ready', $rb->rollback_readiness_state);
        $this->assertTrue($rb->approval_required);
    }

    public function test_validate_rollback_marks_as_validated(): void
    {
        $plan = $this->svc->createExecutionPlan('Test plan');
        $rb   = $this->svc->createRollbackPlan($plan, [['step' => 'revert']]);
        $this->svc->validateRollback($rb);
        $rb->refresh();

        $this->assertSame('validated', $rb->rollback_readiness_state);
        $this->assertTrue($rb->simulation_validated);

        $plan->refresh();
        $this->assertTrue($plan->rollback_ready);
    }

    // =========================================================================
    // Execution results — append-only
    // =========================================================================

    public function test_record_step_result_creates_append_only_record(): void
    {
        $plan = $this->svc->createExecutionPlan('Test plan');
        $step = $this->svc->addStepToplan($plan, 'Notify', 'notify_analyst', 1);

        $result = $this->svc->recordStepResult($plan, $step, true, 'notification', 'Analyst notified', []);
        $this->assertStringStartsWith('ser-', $result->result_id);
        $this->assertTrue($result->success);
        $this->assertTrue($result->is_advisory);
    }

    public function test_execution_result_is_append_only(): void
    {
        $plan   = $this->svc->createExecutionPlan('Test plan');
        $step   = $this->svc->addStepToplan($plan, 'Notify', 'notify_analyst', 1);
        $result = $this->svc->recordStepResult($plan, $step, true, 'notification');

        $this->expectException(\LogicException::class);
        $result->success = false;
        $result->save();
    }

    // =========================================================================
    // Audit trail
    // =========================================================================

    public function test_audit_trail_records_plan_creation(): void
    {
        $plan  = $this->svc->createExecutionPlan('Audit test');
        $audit = $this->svc->getAuditTrail($plan->plan_id);

        $this->assertGreaterThanOrEqual(1, $audit->count());
        $this->assertSame(SoarExecutionAudit::EVENT_PLAN_CREATED, $audit->first()->event_type);
    }

    public function test_audit_records_simulation_event(): void
    {
        $plan = $this->svc->createExecutionPlan('Audit test');
        $this->svc->addStepToplan($plan, 'Notify', 'notify_analyst', 1);
        $this->svc->runSimulation($plan);

        $audit = $this->svc->getAuditTrail($plan->plan_id);
        $types = $audit->pluck('event_type')->toArray();

        $this->assertContains(SoarExecutionAudit::EVENT_SIMULATION_COMPLETED, $types);
    }

    public function test_audit_records_approval_events(): void
    {
        $plan = $this->svc->createExecutionPlan('Audit test');
        $this->svc->addStepToplan($plan, 'Notify', 'notify_analyst', 1);
        $this->svc->runSimulation($plan);
        $this->svc->requestApproval($plan, $this->user->id);

        $audit = $this->svc->getAuditTrail($plan->plan_id);
        $types = $audit->pluck('event_type')->toArray();

        $this->assertContains(SoarExecutionAudit::EVENT_APPROVAL_REQUESTED, $types);
    }

    public function test_audit_event_is_append_only(): void
    {
        $plan = $this->svc->createExecutionPlan('Audit test');
        $ev   = SoarExecutionAudit::where('plan_id', $plan->plan_id)->first();

        $this->expectException(\LogicException::class);
        $ev->description = 'tampered';
        $ev->save();
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_returns_expected_keys(): void
    {
        $stats = $this->svc->getDashboardStats();

        $this->assertArrayHasKey('total_playbooks', $stats);
        $this->assertArrayHasKey('active_playbooks', $stats);
        $this->assertArrayHasKey('total_plans', $stats);
        $this->assertArrayHasKey('pending_approvals', $stats);
        $this->assertArrayHasKey('total_simulations', $stats);
        $this->assertArrayHasKey('total_approval_requests', $stats);
        $this->assertArrayHasKey('total_execution_results', $stats);
        $this->assertArrayHasKey('total_audit_events', $stats);
        $this->assertTrue($stats['advisory_only']);
        $this->assertFalse($stats['autonomous_execution']);
        $this->assertTrue($stats['simulation_required']);
    }

    // =========================================================================
    // Threat hunting domain support
    // =========================================================================

    public function test_threat_hunting_supports_50_domains(): void
    {
        $this->assertCount(55, $this->hunting->supportedDomains());
    }

    public function test_hunt_soar_playbooks_domain(): void
    {
        $this->svc->createPlaybook('Hunt test playbook');
        $results = $this->hunting->hunt('soar_playbooks', ['status' => 'draft']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_soar_execution_plans_domain(): void
    {
        $this->svc->createExecutionPlan('Hunt test plan');
        $results = $this->hunting->hunt('soar_execution_plans', ['status' => 'draft']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_soar_simulation_results_domain(): void
    {
        $plan = $this->svc->createExecutionPlan('Test');
        $this->svc->addStepToplan($plan, 'Notify', 'notify_analyst', 1);
        $this->svc->runSimulation($plan);

        $results = $this->hunting->hunt('soar_simulation_results', ['is_advisory' => true]);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_soar_approval_requests_domain(): void
    {
        $plan = $this->svc->createExecutionPlan('Test');
        $this->svc->addStepToplan($plan, 'Notify', 'notify_analyst', 1);
        $this->svc->runSimulation($plan);
        $this->svc->requestApproval($plan, $this->user->id);

        $results = $this->hunting->hunt('soar_approval_requests', ['decision' => 'pending']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    // =========================================================================
    // Risk scoring SOAR factors
    // =========================================================================

    public function test_entity_risk_scoring_has_soar_weights(): void
    {
        $weights = EntityRiskScoringService::WEIGHTS;

        $this->assertArrayHasKey('orchestration_relevance_factor', $weights);
        $this->assertArrayHasKey('repeated_simulation_factor', $weights);
        $this->assertArrayHasKey('soar_blast_radius_factor', $weights);
        $this->assertArrayHasKey('rollback_readiness_factor', $weights);

        $this->assertSame(1.5, $weights['orchestration_relevance_factor']);
        $this->assertSame(1.0, $weights['repeated_simulation_factor']);
        $this->assertSame(2.0, $weights['soar_blast_radius_factor']);
        $this->assertSame(-0.5, $weights['rollback_readiness_factor']);
    }

    // =========================================================================
    // UI routes
    // =========================================================================

    public function test_governance_dashboard_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('soar.governance-dashboard'))
            ->assertStatus(200);
    }

    public function test_governance_dashboard_contains_advisory_notice(): void
    {
        $this->actingAs($this->user)
            ->get(route('soar.governance-dashboard'))
            ->assertSee('simulation-first and approval-gated');
    }

    public function test_playbook_registry_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('soar.playbook-registry'))
            ->assertStatus(200);
    }

    public function test_simulation_runner_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('soar.simulation-runner'))
            ->assertStatus(200);
    }

    public function test_approval_console_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('soar.approval-console'))
            ->assertStatus(200);
    }

    public function test_rollback_viewer_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('soar.rollback-viewer'))
            ->assertStatus(200);
    }

    public function test_audit_timeline_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('soar.audit-timeline'))
            ->assertStatus(200);
    }

    public function test_simulation_artifacts_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('soar.simulation-artifacts'))
            ->assertStatus(200);
    }

    public function test_playbook_version_history_route_is_accessible(): void
    {
        $pb = $this->svc->createPlaybook('Route test');
        $this->actingAs($this->user)
            ->get(route('soar.playbook-version-history', ['playbookId' => $pb->playbook_id]))
            ->assertStatus(200);
    }

    public function test_execution_plan_viewer_route_is_accessible(): void
    {
        $plan = $this->svc->createExecutionPlan('Route test');
        $this->actingAs($this->user)
            ->get(route('soar.execution-plan-viewer', ['planId' => $plan->plan_id]))
            ->assertStatus(200);
    }

    // =========================================================================
    // Safety invariants — no autonomous enforcement methods exist
    // =========================================================================

    public function test_soar_service_has_no_isolate_host(): void
    {
        $this->assertFalse(method_exists(SoarOrchestrationService::class, 'isolateHost'));
    }

    public function test_soar_service_has_no_quarantine_host(): void
    {
        $this->assertFalse(method_exists(SoarOrchestrationService::class, 'quarantineHost'));
    }

    public function test_soar_service_has_no_execute_shell(): void
    {
        $this->assertFalse(method_exists(SoarOrchestrationService::class, 'executeShell'));
    }

    public function test_soar_service_has_no_kill_process(): void
    {
        $this->assertFalse(method_exists(SoarOrchestrationService::class, 'killProcess'));
    }

    public function test_soar_service_has_no_auto_remediate(): void
    {
        $this->assertFalse(method_exists(SoarOrchestrationService::class, 'autoRemediate'));
    }

    public function test_soar_service_has_no_bypass_approval(): void
    {
        $this->assertFalse(method_exists(SoarOrchestrationService::class, 'bypassApproval'));
    }

    public function test_soar_service_has_no_live_endpoint_isolation(): void
    {
        $this->assertFalse(method_exists(SoarOrchestrationService::class, 'liveIsolateEndpoint'));
    }

    public function test_soar_service_has_no_live_firewall_block(): void
    {
        $this->assertFalse(method_exists(SoarOrchestrationService::class, 'liveBlockFirewall'));
    }

    public function test_soar_service_has_no_live_credential_disable(): void
    {
        $this->assertFalse(method_exists(SoarOrchestrationService::class, 'disableCredentialLive'));
    }

    public function test_simulation_only_actions_are_clearly_defined(): void
    {
        $simOnly = SoarPlaybook::SIMULATION_ONLY_ACTIONS;
        foreach ($simOnly as $action) {
            $this->assertStringStartsWith('simulate_', $action,
                "Simulation-only actions must start with 'simulate_': {$action}");
        }
    }

    public function test_all_phase1_actions_are_in_allowed_list(): void
    {
        $allowed = SoarPlaybook::ALLOWED_PHASE1_ACTIONS;
        $this->assertContains('notify_analyst', $allowed);
        $this->assertContains('create_incident', $allowed);
        $this->assertContains('simulate_endpoint_isolation', $allowed);
        $this->assertNotContains('kill_process', $allowed);
        $this->assertNotContains('isolate_host', $allowed);
        $this->assertNotContains('execute_shell', $allowed);
    }

    public function test_execution_results_are_advisory_only(): void
    {
        $plan = $this->svc->createExecutionPlan('Safety test');
        $step = $this->svc->addStepToplan($plan, 'Notify', 'notify_analyst', 1);
        $r    = $this->svc->recordStepResult($plan, $step, true, 'notification');

        $this->assertTrue($r->is_advisory);
    }

    public function test_simulation_results_are_advisory_only(): void
    {
        $plan = $this->svc->createExecutionPlan('Safety test');
        $this->svc->addStepToplan($plan, 'Notify', 'notify_analyst', 1);
        $sim = $this->svc->runSimulation($plan);

        $this->assertTrue($sim->is_advisory);
    }

    public function test_audit_events_are_advisory_only(): void
    {
        $plan = $this->svc->createExecutionPlan('Safety test');
        $audits = SoarExecutionAudit::where('plan_id', $plan->plan_id)->get();

        foreach ($audits as $audit) {
            $this->assertTrue($audit->is_advisory, "Audit event {$audit->event_type} must be advisory");
        }
    }
}
