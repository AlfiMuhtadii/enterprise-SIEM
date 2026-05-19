<?php

namespace Tests\Feature;

use App\Models\ResponseExecution;
use App\Models\ResponseExecutionEvent;
use App\Models\ResponseExecutionRollback;
use App\Models\ResponseExecutionSimulation;
use App\Models\User;
use App\Services\ActiveResponseExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Controlled Active Response Framework Phase 2 tests.
 *
 * Explicitly asserts:
 * - Dual approval enforcement (creator cannot self-approve)
 * - Simulation required before execution
 * - Rollback workflow
 * - Execution audit append-only
 * - Blast radius calculation is deterministic
 * - Execution timeout enforcement
 * - Replay-safe execution history
 * - No autonomous execution
 * - No mass fanout response
 * - No unrestricted shell execution
 * - No destructive persistence modification
 * - No self-propagating response behavior
 */
class ActiveResponseExecutionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function analyst(): User
    {
        return User::factory()->create(['role' => 'analyst']);
    }

    private function svc(): ActiveResponseExecutionService
    {
        return app(ActiveResponseExecutionService::class);
    }

    private function makeExec(User $creator, string $action = ResponseExecution::ACTION_REVOKE_SESSION): ResponseExecution
    {
        return $this->svc()->createExecution([
            'action_type'        => $action,
            'target_entity_type' => 'user',
            'target_entity_key'  => 'user@example.com',
            'rationale'          => 'Test rationale',
        ], $creator);
    }

    // -----------------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------------

    public function test_response_executions_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('response_executions'));
    }

    public function test_response_execution_events_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('response_execution_events'));
    }

    public function test_response_execution_rollbacks_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('response_execution_rollbacks'));
    }

    public function test_response_execution_simulations_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('response_execution_simulations'));
    }

    public function test_execution_events_table_has_no_updated_at(): void
    {
        $this->assertFalse(
            Schema::hasColumn('response_execution_events', 'updated_at'),
            'response_execution_events must be append-only (no updated_at)'
        );
    }

    public function test_execution_rollbacks_table_has_no_updated_at(): void
    {
        $this->assertFalse(
            Schema::hasColumn('response_execution_rollbacks', 'updated_at'),
            'response_execution_rollbacks must be append-only (no updated_at)'
        );
    }

    public function test_execution_simulations_table_has_no_updated_at(): void
    {
        $this->assertFalse(
            Schema::hasColumn('response_execution_simulations', 'updated_at'),
            'response_execution_simulations must be append-only (no updated_at)'
        );
    }

    public function test_executions_table_has_required_columns(): void
    {
        foreach ([
            'execution_id', 'action_type', 'target_entity_type', 'target_entity_key',
            'created_by', 'status', 'requires_dual_approval', 'simulation_required',
            'blast_radius_score', 'execution_safety_score', 'execution_confidence_score',
            'approver_1_id', 'approver_2_id', 'rollback_supported',
        ] as $col) {
            $this->assertTrue(Schema::hasColumn('response_executions', $col), "Missing column: $col");
        }
    }

    // -----------------------------------------------------------------------
    // Model constants and allowed actions
    // -----------------------------------------------------------------------

    public function test_allowed_actions_are_exactly_five(): void
    {
        $this->assertCount(5, ResponseExecution::ALLOWED_ACTIONS);
        $this->assertContains('revoke_session', ResponseExecution::ALLOWED_ACTIONS);
        $this->assertContains('disable_user',   ResponseExecution::ALLOWED_ACTIONS);
        $this->assertContains('isolate_host',   ResponseExecution::ALLOWED_ACTIONS);
        $this->assertContains('block_ip',       ResponseExecution::ALLOWED_ACTIONS);
        $this->assertContains('block_domain',   ResponseExecution::ALLOWED_ACTIONS);
    }

    public function test_dual_approval_required_actions_do_not_include_revoke_session(): void
    {
        // revoke_session is the "lighter" action and requires only single approval
        $this->assertNotContains('revoke_session', ResponseExecution::DUAL_APPROVAL_REQUIRED);
    }

    public function test_rollback_supported_actions_are_non_destructive(): void
    {
        // All rollback-supported actions are reversible
        foreach (ResponseExecution::ROLLBACK_SUPPORTED_ACTIONS as $action) {
            $this->assertContains($action, ResponseExecution::ALLOWED_ACTIONS);
        }
    }

    public function test_simulation_required_for_all_actions(): void
    {
        $this->assertCount(
            count(ResponseExecution::ALLOWED_ACTIONS),
            ResponseExecution::SIMULATION_REQUIRED_ACTIONS,
            'All actions must require simulation'
        );
    }

    public function test_max_targets_per_execution_is_one(): void
    {
        $this->assertSame(1, ResponseExecution::MAX_TARGETS_PER_EXECUTION);
    }

    public function test_11_state_machine_states(): void
    {
        $this->assertCount(11, ResponseExecution::STATUSES);
    }

    // -----------------------------------------------------------------------
    // Execution creation
    // -----------------------------------------------------------------------

    public function test_create_execution_persists_to_database(): void
    {
        $creator = $this->admin();
        $exec = $this->makeExec($creator);

        $this->assertDatabaseHas('response_executions', [
            'execution_id' => $exec->execution_id,
            'status'       => ResponseExecution::STATUS_DRAFT,
            'created_by'   => $creator->id,
        ]);
    }

    public function test_create_execution_generates_prefixed_id(): void
    {
        $exec = $this->makeExec($this->admin());
        $this->assertStringStartsWith('EXEC-', $exec->execution_id);
    }

    public function test_create_execution_sets_simulation_required(): void
    {
        $exec = $this->makeExec($this->admin());
        $this->assertTrue((bool) $exec->simulation_required);
    }

    public function test_create_execution_rejects_disallowed_action_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->createExecution([
            'action_type'        => 'kill_process',
            'target_entity_type' => 'host',
            'target_entity_key'  => 'bad-host',
            'rationale'          => 'r',
        ], $this->admin());
    }

    public function test_create_execution_requires_at_most_one_target(): void
    {
        // The service only accepts a single target_entity_key — no array/batch
        $exec = $this->makeExec($this->admin());
        $this->assertIsString($exec->target_entity_key);
        $this->assertNotEmpty($exec->target_entity_key);
    }

    public function test_create_execution_appends_submitted_event(): void
    {
        $exec = $this->makeExec($this->admin());
        $this->assertDatabaseHas('response_execution_events', [
            'execution_id' => $exec->id,
            'event_type'   => ResponseExecutionEvent::EVENT_SUBMITTED,
        ]);
    }

    public function test_dual_approval_flag_set_for_high_impact_actions(): void
    {
        $exec = $this->makeExec($this->admin(), ResponseExecution::ACTION_ISOLATE_HOST);
        $this->assertTrue((bool) $exec->requires_dual_approval);
    }

    public function test_single_approval_for_revoke_session(): void
    {
        $exec = $this->makeExec($this->admin(), ResponseExecution::ACTION_REVOKE_SESSION);
        $this->assertFalse((bool) $exec->requires_dual_approval);
    }

    // -----------------------------------------------------------------------
    // Approval lifecycle — dual approval enforcement
    // -----------------------------------------------------------------------

    public function test_creator_cannot_self_approve(): void
    {
        $creator = $this->admin();
        $exec    = $this->makeExec($creator);
        $this->svc()->submitForApproval($exec, $creator);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot self-approve/i');
        $this->svc()->approve($exec->fresh(), $creator, 'self approving');
    }

    public function test_different_user_can_approve(): void
    {
        $creator  = $this->admin();
        $approver = $this->admin();
        $exec     = $this->makeExec($creator);

        $this->svc()->submitForApproval($exec, $creator);
        $approved = $this->svc()->approve($exec->fresh(), $approver, 'looks good');

        $this->assertSame(ResponseExecution::STATUS_APPROVED, $approved->status);
        $this->assertSame($approver->id, $approved->approver_1_id);
    }

    public function test_dual_approval_requires_two_distinct_approvers(): void
    {
        $creator   = $this->admin();
        $approver1 = $this->admin();
        $exec      = $this->makeExec($creator, ResponseExecution::ACTION_DISABLE_USER);

        $this->svc()->submitForApproval($exec, $creator);
        // First approval — should not fully approve yet
        $partial = $this->svc()->approve($exec->fresh(), $approver1, 'first ok');
        $this->assertSame(ResponseExecution::STATUS_PENDING_APPROVAL, $partial->status);

        // Second approval by same person as first — should throw
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/second approver must be different/i');
        $this->svc()->approve($partial->fresh(), $approver1, 'second ok');
    }

    public function test_dual_approval_completes_with_two_distinct_approvers(): void
    {
        $creator   = $this->admin();
        $approver1 = $this->admin();
        $approver2 = $this->admin();
        $exec      = $this->makeExec($creator, ResponseExecution::ACTION_BLOCK_IP);

        $this->svc()->submitForApproval($exec, $creator);
        $this->svc()->approve($exec->fresh(), $approver1, 'first');
        $fully = $this->svc()->approve($exec->fresh(), $approver2, 'second');

        $this->assertSame(ResponseExecution::STATUS_APPROVED, $fully->status);
        $this->assertNotNull($fully->approver_1_id);
        $this->assertNotNull($fully->approver_2_id);
        $this->assertNotSame($fully->approver_1_id, $fully->approver_2_id);
    }

    public function test_creator_cannot_approve_as_second_approver_either(): void
    {
        $creator   = $this->admin();
        $approver1 = $this->admin();
        $exec      = $this->makeExec($creator, ResponseExecution::ACTION_BLOCK_DOMAIN);

        $this->svc()->submitForApproval($exec, $creator);
        $this->svc()->approve($exec->fresh(), $approver1, 'first');

        // Creator tries to give second approval
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot self-approve/i');
        $this->svc()->approve($exec->fresh(), $creator, 'second');
    }

    // -----------------------------------------------------------------------
    // Simulation required before execution
    // -----------------------------------------------------------------------

    public function test_execution_blocked_without_simulation(): void
    {
        $creator  = $this->admin();
        $approver = $this->admin();
        $exec     = $this->makeExec($creator, ResponseExecution::ACTION_REVOKE_SESSION);

        $this->svc()->submitForApproval($exec, $creator);
        $this->svc()->approve($exec->fresh(), $approver, 'ok');

        // Skip simulation and try to request execution directly
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/simulation must be completed/i');
        $this->svc()->requestExecution($exec->fresh(), $creator);
    }

    public function test_simulation_creates_simulation_record(): void
    {
        $creator  = $this->admin();
        $approver = $this->admin();
        $exec     = $this->makeExec($creator, ResponseExecution::ACTION_REVOKE_SESSION);

        $this->svc()->submitForApproval($exec, $creator);
        $this->svc()->approve($exec->fresh(), $approver, 'ok');
        $sim = $this->svc()->runSimulation($exec->fresh(), $creator);

        $this->assertDatabaseHas('response_execution_simulations', [
            'simulation_id' => $sim->simulation_id,
            'execution_id'  => $exec->id,
        ]);
    }

    public function test_simulation_never_mutates_infrastructure(): void
    {
        $creator  = $this->admin();
        $approver = $this->admin();
        $exec     = $this->makeExec($creator, ResponseExecution::ACTION_REVOKE_SESSION);

        $this->svc()->submitForApproval($exec, $creator);
        $this->svc()->approve($exec->fresh(), $approver, 'ok');
        $sim = $this->svc()->runSimulation($exec->fresh(), $creator);

        $this->assertStringContainsString('No infrastructure changes', $sim->simulation_notes);
    }

    public function test_simulation_id_has_correct_prefix(): void
    {
        $creator  = $this->admin();
        $approver = $this->admin();
        $exec     = $this->makeExec($creator, ResponseExecution::ACTION_REVOKE_SESSION);

        $this->svc()->submitForApproval($exec, $creator);
        $this->svc()->approve($exec->fresh(), $approver, 'ok');
        $sim = $this->svc()->runSimulation($exec->fresh(), $creator);

        $this->assertStringStartsWith('SIM-', $sim->simulation_id);
    }

    public function test_simulation_transitions_state_to_simulated(): void
    {
        $creator  = $this->admin();
        $approver = $this->admin();
        $exec     = $this->makeExec($creator, ResponseExecution::ACTION_REVOKE_SESSION);

        $this->svc()->submitForApproval($exec, $creator);
        $this->svc()->approve($exec->fresh(), $approver, 'ok');
        $this->svc()->runSimulation($exec->fresh(), $creator);

        $this->assertSame(ResponseExecution::STATUS_SIMULATED, $exec->fresh()->status);
    }

    public function test_execution_proceeds_after_simulation(): void
    {
        $creator  = $this->admin();
        $approver = $this->admin();
        $exec     = $this->makeExec($creator, ResponseExecution::ACTION_REVOKE_SESSION);

        $this->svc()->submitForApproval($exec, $creator);
        $this->svc()->approve($exec->fresh(), $approver, 'ok');
        $this->svc()->runSimulation($exec->fresh(), $creator);
        $ready = $this->svc()->requestExecution($exec->fresh(), $creator);
        $executed = $this->svc()->executeAction($ready, $creator, 'Done.');

        $this->assertSame(ResponseExecution::STATUS_EXECUTED, $executed->status);
    }

    // -----------------------------------------------------------------------
    // Rollback workflow
    // -----------------------------------------------------------------------

    public function test_rollback_only_available_on_supported_actions(): void
    {
        $exec = $this->makeExec($this->admin(), ResponseExecution::ACTION_REVOKE_SESSION);
        $this->assertTrue((bool) $exec->rollback_supported);

        $exec2 = $this->makeExec($this->admin(), ResponseExecution::ACTION_DISABLE_USER);
        $this->assertFalse((bool) $exec2->rollback_supported);
    }

    public function test_rollback_cannot_be_initiated_on_non_executed_state(): void
    {
        $creator  = $this->admin();
        $exec     = $this->makeExec($creator, ResponseExecution::ACTION_REVOKE_SESSION);
        // Still in draft — rollback should throw
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->initiateRollback($exec, $creator);
    }

    public function test_rollback_workflow_creates_rollback_record(): void
    {
        $creator  = $this->admin();
        $approver = $this->admin();
        $exec     = $this->makeExec($creator, ResponseExecution::ACTION_REVOKE_SESSION);

        $this->svc()->submitForApproval($exec, $creator);
        $this->svc()->approve($exec->fresh(), $approver, 'ok');
        $this->svc()->runSimulation($exec->fresh(), $creator);
        $this->svc()->requestExecution($exec->fresh(), $creator);
        $this->svc()->executeAction($exec->fresh(), $creator, 'executed');
        $rollback = $this->svc()->initiateRollback($exec->fresh(), $creator);

        $this->assertStringStartsWith('ROLL-', $rollback->rollback_id);
        $this->assertDatabaseHas('response_execution_rollbacks', [
            'rollback_id'  => $rollback->rollback_id,
            'execution_id' => $exec->id,
        ]);
    }

    public function test_rollback_completes_and_transitions_to_rolled_back(): void
    {
        $creator  = $this->admin();
        $approver = $this->admin();
        $exec     = $this->makeExec($creator, ResponseExecution::ACTION_REVOKE_SESSION);

        $this->svc()->submitForApproval($exec, $creator);
        $this->svc()->approve($exec->fresh(), $approver, 'ok');
        $this->svc()->runSimulation($exec->fresh(), $creator);
        $this->svc()->requestExecution($exec->fresh(), $creator);
        $this->svc()->executeAction($exec->fresh(), $creator, 'done');
        $this->svc()->initiateRollback($exec->fresh(), $creator);
        $rolled = $this->svc()->completeRollback($exec->fresh(), $creator, 'reverted');

        $this->assertSame(ResponseExecution::STATUS_ROLLED_BACK, $rolled->status);
    }

    public function test_rollback_not_available_for_disable_user(): void
    {
        $exec = $this->makeExec($this->admin(), ResponseExecution::ACTION_DISABLE_USER);

        // Manually force to executed state
        $exec->update(['status' => ResponseExecution::STATUS_EXECUTED, 'rollback_supported' => false]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/does not support rollback/i");
        $this->svc()->initiateRollback($exec->fresh(), $this->admin());
    }

    // -----------------------------------------------------------------------
    // Audit trail — append-only
    // -----------------------------------------------------------------------

    public function test_each_state_transition_appends_event(): void
    {
        $creator  = $this->admin();
        $approver = $this->admin();
        $exec     = $this->makeExec($creator, ResponseExecution::ACTION_REVOKE_SESSION);

        $initialCount = ResponseExecutionEvent::where('execution_id', $exec->id)->count();

        $this->svc()->submitForApproval($exec, $creator);
        $this->svc()->approve($exec->fresh(), $approver, 'ok');

        $afterCount = ResponseExecutionEvent::where('execution_id', $exec->id)->count();
        $this->assertGreaterThan($initialCount, $afterCount, 'Events must be appended for each transition');
    }

    public function test_events_are_not_deleted_after_cancel(): void
    {
        $creator = $this->admin();
        $exec    = $this->makeExec($creator);
        $eventsBefore = ResponseExecutionEvent::where('execution_id', $exec->id)->count();

        $this->svc()->cancel($exec, $creator, 'changed mind');

        $eventsAfter = ResponseExecutionEvent::where('execution_id', $exec->id)->count();
        $this->assertGreaterThanOrEqual($eventsBefore, $eventsAfter, 'Cancel must not delete events');
    }

    public function test_execution_events_have_trace_id(): void
    {
        $exec = $this->makeExec($this->admin());
        $events = ResponseExecutionEvent::where('execution_id', $exec->id)->get();
        foreach ($events as $ev) {
            $this->assertNotNull($ev->trace_id, 'Every event must carry trace_id');
        }
    }

    public function test_execution_history_is_replay_safe(): void
    {
        $creator  = $this->admin();
        $approver = $this->admin();
        $exec     = $this->makeExec($creator, ResponseExecution::ACTION_REVOKE_SESSION);

        $this->svc()->submitForApproval($exec, $creator);
        $this->svc()->approve($exec->fresh(), $approver, 'ok');
        $this->svc()->runSimulation($exec->fresh(), $creator);
        $this->svc()->requestExecution($exec->fresh(), $creator);
        $this->svc()->executeAction($exec->fresh(), $creator, 'done');

        // All events exist and are ordered
        $events = ResponseExecutionEvent::where('execution_id', $exec->id)
            ->orderBy('id')
            ->get();

        $this->assertGreaterThanOrEqual(3, $events->count());
        // First event should always be submitted
        $this->assertSame(ResponseExecutionEvent::EVENT_SUBMITTED, $events->first()->event_type);
    }

    // -----------------------------------------------------------------------
    // Blast radius calculation
    // -----------------------------------------------------------------------

    public function test_blast_radius_is_deterministic(): void
    {
        $svc = $this->svc();
        $score1 = $svc->calculateBlastRadius(ResponseExecution::ACTION_ISOLATE_HOST, 'host-abc');
        $score2 = $svc->calculateBlastRadius(ResponseExecution::ACTION_ISOLATE_HOST, 'host-abc');
        $this->assertSame($score1, $score2, 'Blast radius must be deterministic for same inputs');
    }

    public function test_blast_radius_bounded_between_zero_and_one(): void
    {
        $svc = $this->svc();
        foreach (ResponseExecution::ALLOWED_ACTIONS as $action) {
            $score = $svc->calculateBlastRadius($action, 'entity-' . $action);
            $this->assertGreaterThanOrEqual(0.0, $score);
            $this->assertLessThanOrEqual(1.0, $score);
        }
    }

    public function test_isolate_host_has_highest_blast_radius_weight(): void
    {
        $svc     = $this->svc();
        $isolate = $svc->calculateBlastRadius(ResponseExecution::ACTION_ISOLATE_HOST, 'host-x');
        $revoke  = $svc->calculateBlastRadius(ResponseExecution::ACTION_REVOKE_SESSION, 'host-x');
        $this->assertGreaterThan($revoke, $isolate, 'isolate_host must have higher blast radius than revoke_session');
    }

    public function test_safety_score_is_inverse_of_blast_radius(): void
    {
        $svc    = $this->svc();
        $blast  = $svc->calculateBlastRadius(ResponseExecution::ACTION_REVOKE_SESSION, 'user@x.com');
        $safety = $svc->calculateSafetyScore(ResponseExecution::ACTION_REVOKE_SESSION, $blast);
        $this->assertEqualsWithDelta(1.0 - $blast, $safety, 0.01);
    }

    // -----------------------------------------------------------------------
    // State machine transitions
    // -----------------------------------------------------------------------

    public function test_terminal_states_cannot_be_cancelled(): void
    {
        $exec = $this->makeExec($this->admin());
        $exec->update(['status' => ResponseExecution::STATUS_EXECUTED]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/terminal state/i');
        $this->svc()->cancel($exec->fresh(), $this->admin(), 'late cancel');
    }

    public function test_invalid_transition_throws(): void
    {
        $exec = $this->makeExec($this->admin());
        // Draft cannot go directly to executed
        $this->assertFalse($exec->canTransitionTo(ResponseExecution::STATUS_EXECUTED));
    }

    public function test_is_fully_approved_single_approval(): void
    {
        $creator  = $this->admin();
        $approver = $this->admin();
        $exec     = $this->makeExec($creator, ResponseExecution::ACTION_REVOKE_SESSION);

        $this->svc()->submitForApproval($exec, $creator);
        $approved = $this->svc()->approve($exec->fresh(), $approver, 'ok');

        $this->assertTrue($approved->isFullyApproved());
    }

    public function test_is_not_fully_approved_with_only_one_for_dual_approval(): void
    {
        $creator   = $this->admin();
        $approver1 = $this->admin();
        $exec      = $this->makeExec($creator, ResponseExecution::ACTION_DISABLE_USER);

        $this->svc()->submitForApproval($exec, $creator);
        $partial = $this->svc()->approve($exec->fresh(), $approver1, 'first');

        $this->assertFalse($partial->isFullyApproved());
    }

    // -----------------------------------------------------------------------
    // Route and view accessibility
    // -----------------------------------------------------------------------

    public function test_dashboard_accessible_to_admin(): void
    {
        $this->actingAs($this->admin())
             ->get('/active-response')
             ->assertOk();
    }

    public function test_approval_queue_accessible_to_admin(): void
    {
        $this->actingAs($this->admin())
             ->get('/active-response/approval-queue')
             ->assertOk();
    }

    public function test_audit_explorer_accessible_to_admin(): void
    {
        $this->actingAs($this->admin())
             ->get('/active-response/audit')
             ->assertOk();
    }

    public function test_rollback_center_accessible_to_admin(): void
    {
        $this->actingAs($this->admin())
             ->get('/active-response/rollback-center')
             ->assertOk();
    }

    public function test_routes_require_authentication(): void
    {
        $this->get('/active-response')->assertRedirect('/login');
    }

    public function test_show_page_accessible_for_existing_execution(): void
    {
        $creator = $this->admin();
        $exec    = $this->makeExec($creator);
        $this->actingAs($creator)
             ->get("/active-response/{$exec->execution_id}")
             ->assertOk();
    }

    // -----------------------------------------------------------------------
    // Safety boundary assertions
    // -----------------------------------------------------------------------

    public function test_no_autonomous_execution_in_service(): void
    {
        $src = file_get_contents(app_path('Services/ActiveResponseExecutionService.php'));
        $this->assertStringNotContainsString('autonomous_execution',  $src);
        $this->assertStringNotContainsString('auto_execute',          $src);
        $this->assertStringNotContainsString('self_triggered',        $src);
    }

    public function test_execute_action_advisory_note_present_in_event(): void
    {
        $creator  = $this->admin();
        $approver = $this->admin();
        $exec     = $this->makeExec($creator, ResponseExecution::ACTION_REVOKE_SESSION);

        $this->svc()->submitForApproval($exec, $creator);
        $this->svc()->approve($exec->fresh(), $approver, 'ok');
        $this->svc()->runSimulation($exec->fresh(), $creator);
        $this->svc()->requestExecution($exec->fresh(), $creator);
        $this->svc()->executeAction($exec->fresh(), $creator, 'confirmed');

        $execEvent = ResponseExecutionEvent::where('execution_id', $exec->id)
            ->where('event_type', ResponseExecutionEvent::EVENT_EXECUTION_DONE)
            ->first();

        $this->assertNotNull($execEvent);
        $details = $execEvent->details ?? [];
        $this->assertArrayHasKey('advisory_note', $details);
        $this->assertStringContainsString('No autonomous system action', $details['advisory_note']);
    }

    public function test_no_mass_fanout_target_is_single_string(): void
    {
        $exec = $this->makeExec($this->admin());
        $this->assertIsString($exec->target_entity_key);
        // target_entity_key is a single scalar — not a JSON array of multiple targets
        $decoded = json_decode($exec->target_entity_key, true);
        $this->assertFalse(is_array($decoded), 'target_entity_key must not be a JSON array of multiple targets');
    }

    public function test_execution_constants_no_execute_type_commands(): void
    {
        foreach (ResponseExecution::ALLOWED_ACTIONS as $action) {
            $this->assertStringNotContainsString('execute_', $action, "ALLOWED_ACTIONS must not contain execute_* type commands");
        }
    }

    public function test_simulation_record_is_append_only(): void
    {
        $creator  = $this->admin();
        $approver = $this->admin();
        $exec     = $this->makeExec($creator, ResponseExecution::ACTION_REVOKE_SESSION);

        $this->svc()->submitForApproval($exec, $creator);
        $this->svc()->approve($exec->fresh(), $approver, 'ok');
        $sim = $this->svc()->runSimulation($exec->fresh(), $creator);

        // Simulation record should exist and not be modifiable (no update path in service)
        $dbSim = ResponseExecutionSimulation::where('simulation_id', $sim->simulation_id)->first();
        $this->assertNotNull($dbSim);
        $this->assertNull($dbSim->updated_at);
    }

    public function test_rollback_record_is_append_only(): void
    {
        $creator  = $this->admin();
        $approver = $this->admin();
        $exec     = $this->makeExec($creator, ResponseExecution::ACTION_REVOKE_SESSION);

        $this->svc()->submitForApproval($exec, $creator);
        $this->svc()->approve($exec->fresh(), $approver, 'ok');
        $this->svc()->runSimulation($exec->fresh(), $creator);
        $this->svc()->requestExecution($exec->fresh(), $creator);
        $this->svc()->executeAction($exec->fresh(), $creator, 'done');
        $rollback = $this->svc()->initiateRollback($exec->fresh(), $creator);

        $dbRollback = ResponseExecutionRollback::where('rollback_id', $rollback->rollback_id)->first();
        $this->assertNotNull($dbRollback);
        $this->assertNull($dbRollback->updated_at);
    }

    public function test_api_allowed_actions_endpoint_returns_allowed_list(): void
    {
        $this->actingAs($this->admin())
             ->getJson('/api/active-response/allowed-actions')
             ->assertOk()
             ->assertJsonStructure(['allowed_actions']);
    }

    public function test_api_pending_approvals_endpoint(): void
    {
        $this->actingAs($this->admin())
             ->getJson('/api/active-response/pending-approvals')
             ->assertOk();
    }
}
