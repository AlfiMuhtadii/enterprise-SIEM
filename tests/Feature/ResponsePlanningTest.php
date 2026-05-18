<?php

namespace Tests\Feature;

use App\Models\ResponsePlan;
use App\Models\ResponsePlanAction;
use App\Models\ResponsePlanApproval;
use App\Models\ResponsePlanNote;
use App\Models\User;
use App\Services\EntityRiskScoringService;
use App\Services\ResponsePlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ResponsePlanningTest extends TestCase
{
    use RefreshDatabase;

    private ResponsePlanningService $planning;
    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planning = new ResponsePlanningService();
        $this->analyst  = User::factory()->create(['role' => 'analyst']);
    }

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    public function test_response_plan_tables_exist(): void
    {
        foreach ([
            'response_plans', 'response_plan_actions',
            'response_plan_approvals', 'response_plan_notes',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_response_plan_actions_has_no_execution_columns(): void
    {
        $forbidden = ['executed_at', 'execute_command', 'external_api_endpoint', 'execute_result'];
        foreach ($forbidden as $col) {
            $this->assertFalse(
                Schema::hasColumn('response_plan_actions', $col),
                "response_plan_actions must NOT have column: {$col}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Plan creation
    // -------------------------------------------------------------------------

    public function test_response_plan_creation_initializes_as_draft(): void
    {
        $plan = $this->planning->createPlan([
            'title'      => 'Test Response Plan',
            'risk_level' => 'high',
        ], $this->analyst->id);

        $this->assertSame('draft', $plan->state);
        $this->assertSame($this->analyst->id, $plan->created_by);
        $this->assertStringStartsWith('RP-', $plan->plan_id);
    }

    public function test_plan_generates_unique_plan_id(): void
    {
        $p1 = $this->planning->createPlan(['title' => 'Plan 1', 'risk_level' => 'low'], $this->analyst->id);
        $p2 = $this->planning->createPlan(['title' => 'Plan 2', 'risk_level' => 'low'], $this->analyst->id);

        $this->assertNotSame($p1->plan_id, $p2->plan_id);
    }

    public function test_plan_creation_records_approval_event(): void
    {
        $plan = $this->makePlan();

        $this->assertGreaterThan(0, ResponsePlanApproval::where('response_plan_id', $plan->id)->count());
    }

    public function test_plan_stores_linked_trace_id(): void
    {
        $plan = $this->planning->createPlan([
            'title'    => 'Trace Linked',
            'trace_id' => 'trace-abc-rp-001',
        ], $this->analyst->id);

        $this->assertSame('trace-abc-rp-001', $plan->trace_id);
    }

    public function test_plan_stores_linked_entity_ids(): void
    {
        $plan = $this->planning->createPlan([
            'title'      => 'Entity Linked',
            'entity_ids' => [1, 2, 3],
        ], $this->analyst->id);

        $stored = DB::table('response_plans')->where('id', $plan->id)->first();
        $ids    = json_decode($stored->entity_ids, true);
        $this->assertContains(1, $ids);
    }

    public function test_plan_stores_related_alert_ids(): void
    {
        $plan = $this->planning->createPlan([
            'title'             => 'Alert Linked',
            'related_alert_ids' => ['alert-rp-001', 'alert-rp-002'],
        ], $this->analyst->id);

        $stored = DB::table('response_plans')->where('id', $plan->id)->first();
        $ids    = json_decode($stored->related_alert_ids, true);
        $this->assertContains('alert-rp-001', $ids);
    }

    // -------------------------------------------------------------------------
    // Deterministic recommendation engine
    // -------------------------------------------------------------------------

    public function test_recommendation_engine_is_deterministic(): void
    {
        $entityId = $this->makeEntityWithRisk('user', 'det-user@rp.test', 'critical', [
            ['factor' => 'mfa_failure_burst', 'value' => 2, 'weight' => 2.5, 'contribution' => 5.0,
             'alert_ids' => ['alert-det-001']],
            ['factor' => 'critical_alerts', 'value' => 1, 'weight' => 3.0, 'contribution' => 3.0,
             'alert_ids' => ['alert-det-001']],
        ]);

        $recs1 = $this->planning->generateRecommendationsForEntity($entityId);
        $recs2 = $this->planning->generateRecommendationsForEntity($entityId);

        $this->assertSame(
            array_column($recs1, 'action_type'),
            array_column($recs2, 'action_type'),
            'Recommendation engine must be deterministic'
        );
    }

    public function test_critical_user_gets_reset_password_recommendation(): void
    {
        $entityId = $this->makeEntityWithRisk('user', 'critical-user@rp.test', 'critical', [
            ['factor' => 'mfa_failure_burst', 'value' => 3, 'weight' => 2.5, 'contribution' => 7.5,
             'alert_ids' => ['alert-mfa-001']],
        ]);

        $recs = $this->planning->generateRecommendationsForEntity($entityId);
        $types = array_column($recs, 'action_type');

        $this->assertContains('recommend_reset_password', $types,
            'Critical user with MFA burst must get reset_password recommendation');
    }

    public function test_high_risk_user_gets_revoke_session_recommendation(): void
    {
        $entityId = $this->makeEntityWithRisk('user', 'high-user@rp.test', 'high', [
            ['factor' => 'high_alerts', 'value' => 2, 'weight' => 2.0, 'contribution' => 4.0,
             'alert_ids' => ['alert-high-001']],
        ]);

        $recs  = $this->planning->generateRecommendationsForEntity($entityId);
        $types = array_column($recs, 'action_type');

        $this->assertContains('recommend_revoke_session', $types);
    }

    public function test_critical_user_with_incident_gets_disable_user(): void
    {
        $entityId = $this->makeEntityWithRisk('user', 'inc-user@rp.test', 'critical', [
            ['factor' => 'incident_involvement', 'value' => 1, 'weight' => 3.0, 'contribution' => 3.0,
             'incident_ids' => ['inc-001']],
            ['factor' => 'mfa_failure_burst', 'value' => 2, 'weight' => 2.5, 'contribution' => 5.0,
             'alert_ids' => ['alert-inc-001']],
        ]);

        $recs  = $this->planning->generateRecommendationsForEntity($entityId);
        $types = array_column($recs, 'action_type');

        $this->assertContains('recommend_disable_user', $types);
    }

    public function test_host_with_persistence_gets_collect_forensics(): void
    {
        $entityId = $this->makeEntityWithRisk('host', 'server-persist-01', 'high', [
            ['factor' => 'persistence_indicator', 'value' => 1, 'weight' => 2.5, 'contribution' => 2.5],
        ]);

        $recs  = $this->planning->generateRecommendationsForEntity($entityId);
        $types = array_column($recs, 'action_type');

        $this->assertContains('recommend_collect_forensics', $types);
        $this->assertContains('recommend_remove_persistence', $types);
    }

    public function test_host_with_c2_gets_isolate_host_and_forensics(): void
    {
        $entityId = $this->makeEntityWithRisk('host', 'server-c2-01', 'critical', [
            ['factor' => 'c2_indicator', 'value' => 1, 'weight' => 3.5, 'contribution' => 3.5],
        ]);

        $recs  = $this->planning->generateRecommendationsForEntity($entityId);
        $types = array_column($recs, 'action_type');

        $this->assertContains('recommend_isolate_host', $types);
        $this->assertContains('recommend_collect_forensics', $types);
    }

    // -------------------------------------------------------------------------
    // Advisory-only propagation
    // -------------------------------------------------------------------------

    public function test_shadow_evidence_marks_recommendation_advisory_only(): void
    {
        $entityId = $this->makeEntityWithRisk('host', 'shadow-host-01', 'medium', [
            ['factor' => 'persistence_indicator', 'value' => 1, 'weight' => 2.5, 'contribution' => 2.5,
             'advisory_only' => true],
        ]);

        $recs = $this->planning->generateRecommendationsForEntity($entityId);

        $advisoryRecs = array_filter($recs, fn($r) => $r['advisory_only']);
        $this->assertNotEmpty($advisoryRecs, 'Shadow evidence must produce advisory_only=true recommendations');
    }

    public function test_persistence_indicator_recommendation_is_always_advisory_only(): void
    {
        $entityId = $this->makeEntityWithRisk('host', 'persist-host-02', 'high', [
            ['factor' => 'persistence_indicator', 'value' => 2, 'weight' => 2.5, 'contribution' => 5.0],
        ]);

        $recs = $this->planning->generateRecommendationsForEntity($entityId);
        $persistenceRecs = array_filter($recs, fn($r) => in_array($r['action_type'], [
            'recommend_collect_forensics', 'recommend_remove_persistence', 'recommend_isolate_host',
        ]));

        foreach ($persistenceRecs as $rec) {
            $this->assertTrue($rec['advisory_only'],
                "Persistence/C2-derived recommendation {$rec['action_type']} must be advisory_only=true (shadow domain)");
        }
    }

    public function test_advisory_only_propagated_to_action_when_added(): void
    {
        $plan   = $this->makePlan();
        $action = $this->planning->addAction($plan->id, [
            'action_type'  => 'recommend_isolate_host',
            'reasoning'    => 'C2 detected on shadow endpoint',
            'advisory_only'=> true,
        ], $this->analyst->id);

        $this->assertTrue($action->advisory_only);
        $this->assertDatabaseHas('response_plan_actions', [
            'id'            => $action->id,
            'advisory_only' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Approval workflow
    // -------------------------------------------------------------------------

    public function test_submit_for_approval_transitions_to_pending_approval(): void
    {
        $plan = $this->makePlan();
        $this->planning->submitForApproval($plan->id, $this->analyst->id);

        $this->assertSame('pending_approval', ResponsePlan::find($plan->id)->state);
    }

    public function test_approve_transitions_plan_to_approved(): void
    {
        $plan = $this->makePlan('pending_approval');
        $this->planning->approve($plan->id, $this->analyst->id, 'LGTM');

        $this->assertSame('approved', ResponsePlan::find($plan->id)->state);
    }

    public function test_reject_transitions_plan_to_rejected(): void
    {
        $plan = $this->makePlan('pending_approval');
        $this->planning->reject($plan->id, $this->analyst->id, 'Insufficient evidence');

        $updated = ResponsePlan::find($plan->id);
        $this->assertSame('rejected', $updated->state);
        $this->assertSame('Insufficient evidence', $updated->rejection_reason);
    }

    public function test_mark_completed_documented_transitions_from_approved(): void
    {
        $plan = $this->makePlan('approved');
        $this->planning->markCompleted($plan->id, $this->analyst->id, 'Manually reset password via AD console.');

        $this->assertSame('completed_documented', ResponsePlan::find($plan->id)->state);
    }

    public function test_rejected_plan_can_be_revised_back_to_draft(): void
    {
        $plan = $this->makePlan('rejected');
        $this->planning->transition_to_draft($plan->id, $this->analyst->id);

        $this->assertSame('draft', ResponsePlan::find($plan->id)->state);
    }

    public function test_invalid_state_transition_throws_exception(): void
    {
        $plan = $this->makePlan('draft'); // draft cannot go to approved directly

        $this->expectException(\InvalidArgumentException::class);
        $this->planning->approve($plan->id, $this->analyst->id);
    }

    // -------------------------------------------------------------------------
    // Approval history — append-only
    // -------------------------------------------------------------------------

    public function test_approval_creates_approval_record(): void
    {
        $plan = $this->makePlan('pending_approval');
        $this->planning->approve($plan->id, $this->analyst->id, 'Approved after review');

        $approval = ResponsePlanApproval::where('response_plan_id', $plan->id)
            ->where('decision', 'approved')
            ->first();

        $this->assertNotNull($approval);
        $this->assertSame($this->analyst->id, $approval->approver_user_id);
        $this->assertSame('Approved after review', $approval->notes);
    }

    public function test_approval_history_is_preserved_append_only(): void
    {
        $plan = $this->makePlan('pending_approval');
        $this->planning->reject($plan->id, $this->analyst->id, 'First rejection');

        $this->planning->transition_to_draft($plan->id, $this->analyst->id);
        $this->planning->submitForApproval($plan->id, $this->analyst->id);
        $this->planning->approve($plan->id, $this->analyst->id, 'Approved on second review');

        $approvalCount = ResponsePlanApproval::where('response_plan_id', $plan->id)->count();
        $this->assertGreaterThan(2, $approvalCount, 'All approval records must be preserved — append-only');
    }

    // -------------------------------------------------------------------------
    // No execution — no infrastructure mutation
    // -------------------------------------------------------------------------

    public function test_approve_does_not_mutate_security_alerts(): void
    {
        $plan = $this->makePlan('pending_approval');

        $countBefore = DB::table('security_alerts')->count();
        $this->planning->approve($plan->id, $this->analyst->id);
        $countAfter = DB::table('security_alerts')->count();

        $this->assertSame($countBefore, $countAfter,
            'approve() must not touch security_alerts');
    }

    public function test_approve_does_not_mutate_security_incidents(): void
    {
        $plan = $this->makePlan('pending_approval');

        $countBefore = DB::table('security_incidents')->count();
        $this->planning->approve($plan->id, $this->analyst->id);
        $countAfter = DB::table('security_incidents')->count();

        $this->assertSame($countBefore, $countAfter,
            'approve() must not touch security_incidents');
    }

    public function test_completed_documented_does_not_mutate_pipeline_tables(): void
    {
        $plan = $this->makePlan('approved');

        $alertsBefore    = DB::table('security_alerts')->count();
        $incidentsBefore = DB::table('security_incidents')->count();

        $this->planning->markCompleted($plan->id, $this->analyst->id, 'Done manually.');

        $this->assertSame($alertsBefore, DB::table('security_alerts')->count(),
            'markCompleted must not touch security_alerts');
        $this->assertSame($incidentsBefore, DB::table('security_incidents')->count(),
            'markCompleted must not touch security_incidents');
    }

    public function test_no_endpoint_isolation_command_created(): void
    {
        $plan = $this->makePlan('pending_approval');
        $this->planning->addAction($plan->id, [
            'action_type'   => 'recommend_isolate_host',
            'advisory_only' => true,
            'reasoning'     => 'C2 detected',
        ], $this->analyst->id);

        $this->planning->approve($plan->id, $this->analyst->id);

        // No record in any command/isolation table
        $this->assertFalse(Schema::hasTable('endpoint_isolation_commands'),
            'There must be no endpoint_isolation_commands table');

        // Action status changes to approved (planning state) — no execution
        $action = ResponsePlanAction::where('response_plan_id', $plan->id)->first();
        $this->assertSame('approved', $action->status,
            'Action status=approved means approved for manual consideration, NOT executed');
        $this->assertNull($action->fresh()->getRawOriginal('executed_at') ?? null);
    }

    public function test_no_process_kill_command_created(): void
    {
        $plan = $this->makePlan('pending_approval');
        $this->planning->addAction($plan->id, [
            'action_type'   => 'recommend_remove_persistence',
            'advisory_only' => true,
            'reasoning'     => 'Persistence detected',
        ], $this->analyst->id);

        $this->planning->approve($plan->id, $this->analyst->id);

        $this->assertFalse(Schema::hasTable('process_kill_commands'),
            'There must be no process_kill_commands table');

        $action = ResponsePlanAction::where('response_plan_id', $plan->id)->first();
        $this->assertNull($action->fresh()->getRawOriginal('executed_at') ?? null);
    }

    public function test_no_external_api_call_executed(): void
    {
        Http::fake(); // Intercept any HTTP calls

        $plan = $this->makePlan('pending_approval');
        $this->planning->approve($plan->id, $this->analyst->id);
        $this->planning->markCompleted($plan->id, $this->analyst->id);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Notes
    // -------------------------------------------------------------------------

    public function test_add_note_appends_to_plan(): void
    {
        $plan = $this->makePlan();
        $this->planning->addNote($plan->id, $this->analyst->id, 'evidence', 'Found IOC in alert evidence.');

        $this->assertDatabaseHas('response_plan_notes', [
            'response_plan_id' => $plan->id,
            'body'             => 'Found IOC in alert evidence.',
        ]);
    }

    public function test_multiple_notes_are_all_preserved(): void
    {
        $plan = $this->makePlan();
        for ($i = 1; $i <= 4; $i++) {
            $this->planning->addNote($plan->id, $this->analyst->id, 'general', "Note {$i}");
        }

        $this->assertSame(4, ResponsePlanNote::where('response_plan_id', $plan->id)->count());
    }

    // -------------------------------------------------------------------------
    // Timeline
    // -------------------------------------------------------------------------

    public function test_get_timeline_returns_events_in_order(): void
    {
        $plan = $this->makePlan();
        $this->planning->submitForApproval($plan->id, $this->analyst->id);
        $this->planning->addNote($plan->id, $this->analyst->id, 'general', 'Timeline test note.');

        $timeline = $this->planning->getTimeline($plan->id);

        $this->assertGreaterThanOrEqual(2, $timeline->count());
        $times = $timeline->pluck('occurred_at')->toArray();
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times, 'Timeline must be chronologically ordered');
    }

    // -------------------------------------------------------------------------
    // Audit trail
    // -------------------------------------------------------------------------

    public function test_audit_trail_complete_across_full_lifecycle(): void
    {
        $approver = User::factory()->create(['role' => 'analyst']);
        $plan     = $this->makePlan();

        $this->planning->addNote($plan->id, $this->analyst->id, 'evidence', 'Evidence noted.');
        $this->planning->submitForApproval($plan->id, $this->analyst->id);
        $this->planning->approve($plan->id, $approver->id, 'Reviewed and approved.');
        $this->planning->markCompleted($plan->id, $this->analyst->id, 'Manual actions completed externally.');

        $approvalCount = ResponsePlanApproval::where('response_plan_id', $plan->id)->count();
        $noteCount     = ResponsePlanNote::where('response_plan_id', $plan->id)->count();

        $this->assertGreaterThanOrEqual(3, $approvalCount, 'Full lifecycle must leave audit trail');
        $this->assertGreaterThanOrEqual(1, $noteCount);

        $timeline  = $this->planning->getTimeline($plan->id);
        $decisions = $timeline->where('timeline_type', 'approval')->pluck('decision')->toArray();
        $this->assertContains('approved', $decisions);
        $this->assertContains('completed', $decisions);
    }

    // -------------------------------------------------------------------------
    // RBAC / Authorization
    // -------------------------------------------------------------------------

    public function test_api_response_plans_index_requires_authentication(): void
    {
        $this->get('/api/response-plans')->assertRedirect();
    }

    public function test_api_response_plans_approve_requires_authentication(): void
    {
        $this->postJson('/api/response-plans/1/approve', [])->assertUnauthorized();
    }

    public function test_api_response_plans_reject_requires_authentication(): void
    {
        $this->postJson('/api/response-plans/1/reject', ['reason' => 'x'])->assertUnauthorized();
    }

    public function test_api_response_plans_notes_requires_authentication(): void
    {
        $this->postJson('/api/response-plans/1/notes', [])->assertUnauthorized();
    }

    public function test_viewer_can_view_response_plans(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->get('/api/response-plans')
            ->assertOk()
            ->assertJsonStructure(['plans', 'total', 'counts']);
    }

    public function test_viewer_cannot_create_response_plan(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->post('/response-plans', [
            'title'      => 'Unauthorized Plan',
            'risk_level' => 'high',
        ])->assertForbidden();
    }

    public function test_analyst_can_create_response_plan(): void
    {
        $this->actingAs($this->analyst)->post('/response-plans', [
            'title'      => 'Analyst Plan',
            'risk_level' => 'high',
        ])->assertRedirect();

        $this->assertDatabaseHas('response_plans', ['title' => 'Analyst Plan']);
    }

    public function test_response_plan_dashboard_requires_auth(): void
    {
        $this->get('/response-plans')->assertRedirect();
    }

    public function test_response_plan_dashboard_returns_200_for_analyst(): void
    {
        $this->actingAs($this->analyst)->get('/response-plans')->assertOk();
    }

    public function test_api_approve_transitions_plan(): void
    {
        $plan = $this->makePlan('pending_approval');

        $this->actingAs($this->analyst)->postJson("/api/response-plans/{$plan->id}/approve", [
            'notes' => 'API approval',
        ])->assertOk()->assertJson(['status' => 'approved']);

        $this->assertSame('approved', ResponsePlan::find($plan->id)->state);
    }

    public function test_api_reject_transitions_plan(): void
    {
        $plan = $this->makePlan('pending_approval');

        $this->actingAs($this->analyst)->postJson("/api/response-plans/{$plan->id}/reject", [
            'reason' => 'API rejection reason',
        ])->assertOk()->assertJson(['status' => 'rejected']);

        $this->assertSame('rejected', ResponsePlan::find($plan->id)->state);
    }

    public function test_api_approve_returns_422_on_invalid_transition(): void
    {
        $plan = $this->makePlan('draft'); // cannot approve from draft

        $this->actingAs($this->analyst)->postJson("/api/response-plans/{$plan->id}/approve", [])
            ->assertStatus(422);
    }

    public function test_api_show_returns_404_for_missing_plan(): void
    {
        $this->actingAs($this->analyst)->get('/api/response-plans/99999')
            ->assertNotFound()->assertJson(['error' => 'plan_not_found']);
    }

    // -------------------------------------------------------------------------
    // Grafana dashboard
    // -------------------------------------------------------------------------

    public function test_response_planning_grafana_dashboard_exists(): void
    {
        $path = base_path('docs/grafana/response-planning-dashboard.json');
        $this->assertFileExists($path);

        $json = json_decode(file_get_contents($path), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('panels', $json);
        $this->assertNotEmpty($json['panels']);
        $this->assertSame('XDR Response Planning Dashboard', $json['title']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makePlan(string $state = 'draft'): ResponsePlan
    {
        $plan = $this->planning->createPlan([
            'title'      => 'Test Response Plan',
            'risk_level' => 'high',
        ], $this->analyst->id);

        if ($state !== 'draft') {
            DB::table('response_plans')->where('id', $plan->id)->update(['state' => $state]);
            $plan->state = $state;
        }

        return $plan;
    }

    private function makeEntityWithRisk(string $type, string $key, string $riskLevel, array $factors): int
    {
        return DB::table('entities')->insertGetId([
            'entity_type'       => $type,
            'entity_key'        => $key,
            'display_name'      => $key,
            'first_seen_at'     => '2026-05-17 09:00:00',
            'last_seen_at'      => '2026-05-17 10:00:00',
            'observation_count' => 3,
            'risk_score'        => match($riskLevel) { 'critical' => 8.5, 'high' => 6.5, 'medium' => 4.0, default => 1.5 },
            'risk_level'        => $riskLevel,
            'risk_factors'      => json_encode($factors),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }
}
