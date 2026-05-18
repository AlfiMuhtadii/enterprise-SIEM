<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\InvestigationAssignment;
use App\Models\InvestigationEvent;
use App\Models\InvestigationNote;
use App\Models\InvestigationArtifact;
use App\Models\User;
use App\Services\InvestigationOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InvestigationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private InvestigationOrchestratorService $orchestrator;
    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orchestrator = new InvestigationOrchestratorService();
        $this->analyst      = User::factory()->create(['role' => 'analyst']);
    }

    // -------------------------------------------------------------------------
    // Schema integrity
    // -------------------------------------------------------------------------

    public function test_investigation_tables_exist(): void
    {
        foreach ([
            'investigations', 'investigation_events',
            'investigation_assignments', 'investigation_notes', 'investigation_artifacts',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_investigations_table_has_required_columns(): void
    {
        foreach ([
            'investigation_id', 'title', 'state', 'severity', 'priority',
            'created_by', 'assigned_to', 'trace_id', 'alert_ids', 'incident_ids', 'entity_ids',
            'triage_at', 'resolved_at', 'closed_at',
        ] as $col) {
            $this->assertTrue(Schema::hasColumn('investigations', $col), "investigations missing: {$col}");
        }
    }

    // -------------------------------------------------------------------------
    // Investigation creation
    // -------------------------------------------------------------------------

    public function test_investigation_creation_initializes_with_new_state(): void
    {
        $inv = $this->orchestrator->createInvestigation([
            'title'    => 'Test Investigation',
            'severity' => 'high',
        ], $this->analyst->id);

        $this->assertSame('new', $inv->state);
        $this->assertSame('high', $inv->severity);
        $this->assertSame($this->analyst->id, $inv->created_by);
    }

    public function test_investigation_generates_unique_investigation_id(): void
    {
        $inv1 = $this->orchestrator->createInvestigation(['title' => 'Inv 1', 'severity' => 'low'], $this->analyst->id);
        $inv2 = $this->orchestrator->createInvestigation(['title' => 'Inv 2', 'severity' => 'low'], $this->analyst->id);

        $this->assertNotSame($inv1->investigation_id, $inv2->investigation_id);
        $this->assertStringStartsWith('INV-', $inv1->investigation_id);
    }

    public function test_creation_appends_created_event(): void
    {
        $inv = $this->orchestrator->createInvestigation(['title' => 'Evt Test', 'severity' => 'medium'], $this->analyst->id);

        $event = InvestigationEvent::where('investigation_id', $inv->id)
            ->where('event_type', 'created')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame($this->analyst->id, $event->actor_user_id);
    }

    public function test_investigation_stores_linked_trace_id(): void
    {
        $inv = $this->orchestrator->createInvestigation([
            'title'    => 'Trace Linked',
            'severity' => 'high',
            'trace_id' => 'trace-abc-123',
        ], $this->analyst->id);

        $this->assertSame('trace-abc-123', $inv->trace_id);
    }

    public function test_investigation_stores_linked_alert_ids(): void
    {
        $inv = $this->orchestrator->createInvestigation([
            'title'     => 'Alert Linked',
            'severity'  => 'high',
            'alert_ids' => ['alert-001', 'alert-002'],
        ], $this->analyst->id);

        $stored = DB::table('investigations')->where('id', $inv->id)->first();
        $ids    = json_decode($stored->alert_ids, true);

        $this->assertContains('alert-001', $ids);
        $this->assertContains('alert-002', $ids);
    }

    public function test_investigation_stores_entity_ids(): void
    {
        $inv = $this->orchestrator->createInvestigation([
            'title'      => 'Entity Linked',
            'severity'   => 'medium',
            'entity_ids' => [1, 2, 3],
        ], $this->analyst->id);

        $stored = DB::table('investigations')->where('id', $inv->id)->first();
        $ids    = json_decode($stored->entity_ids, true);

        $this->assertContains(1, $ids);
    }

    // -------------------------------------------------------------------------
    // State transitions
    // -------------------------------------------------------------------------

    public function test_valid_state_transition_updates_state(): void
    {
        $inv = $this->makeInvestigation();

        $this->orchestrator->transitionState($inv->id, 'triaged', $this->analyst->id);

        $updated = Investigation::find($inv->id);
        $this->assertSame('triaged', $updated->state);
    }

    public function test_invalid_state_transition_throws_exception(): void
    {
        $inv = $this->makeInvestigation(); // state = 'new'

        $this->expectException(\InvalidArgumentException::class);
        $this->orchestrator->transitionState($inv->id, 'resolved', $this->analyst->id);
    }

    public function test_all_state_transitions_are_defined(): void
    {
        foreach (Investigation::VALID_STATES as $state) {
            $this->assertArrayHasKey($state, Investigation::STATE_TRANSITIONS,
                "STATE_TRANSITIONS missing key: {$state}");
        }
    }

    public function test_state_transition_appends_event(): void
    {
        $inv = $this->makeInvestigation();
        $this->orchestrator->transitionState($inv->id, 'triaged', $this->analyst->id);

        $event = InvestigationEvent::where('investigation_id', $inv->id)
            ->where('event_type', 'state_transition')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('new', $event->from_state);
        $this->assertSame('triaged', $event->to_state);
    }

    public function test_triage_at_timestamp_set_on_triaged_transition(): void
    {
        $inv = $this->makeInvestigation();
        $this->orchestrator->transitionState($inv->id, 'triaged', $this->analyst->id);

        $updated = Investigation::find($inv->id);
        $this->assertNotNull($updated->triage_at);
    }

    public function test_resolved_at_timestamp_set_on_resolved_transition(): void
    {
        $inv = $this->makeInvestigation('investigating');
        $this->orchestrator->transitionState($inv->id, 'resolved', $this->analyst->id);

        $updated = Investigation::find($inv->id);
        $this->assertNotNull($updated->resolved_at);
    }

    public function test_false_positive_transition_from_new(): void
    {
        $inv = $this->makeInvestigation();
        $this->orchestrator->transitionState($inv->id, 'false_positive', $this->analyst->id);

        $this->assertSame('false_positive', Investigation::find($inv->id)->state);
    }

    // -------------------------------------------------------------------------
    // Assignment history
    // -------------------------------------------------------------------------

    public function test_assignment_creates_assignment_record(): void
    {
        $inv = $this->makeInvestigation();
        $this->orchestrator->assignAnalyst($inv->id, $this->analyst->id, $this->analyst->id);

        $assignment = InvestigationAssignment::where('investigation_id', $inv->id)
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($assignment);
        $this->assertSame($this->analyst->id, $assignment->assigned_to_user_id);
    }

    public function test_reassignment_deactivates_previous_assignment(): void
    {
        $analyst2 = User::factory()->create(['role' => 'analyst']);
        $inv      = $this->makeInvestigation();

        $this->orchestrator->assignAnalyst($inv->id, $this->analyst->id, $this->analyst->id);
        $this->orchestrator->assignAnalyst($inv->id, $analyst2->id, $this->analyst->id);

        $prev = InvestigationAssignment::where('investigation_id', $inv->id)
            ->where('assigned_to_user_id', $this->analyst->id)
            ->first();
        $curr = InvestigationAssignment::where('investigation_id', $inv->id)
            ->where('is_active', true)
            ->first();

        $this->assertFalse((bool) $prev->is_active);
        $this->assertSame($analyst2->id, $curr->assigned_to_user_id);
    }

    public function test_assignment_history_is_preserved_as_append_only(): void
    {
        $analyst2 = User::factory()->create(['role' => 'analyst']);
        $inv      = $this->makeInvestigation();

        $this->orchestrator->assignAnalyst($inv->id, $this->analyst->id, $this->analyst->id);
        $this->orchestrator->assignAnalyst($inv->id, $analyst2->id, $this->analyst->id);

        $totalAssignments = InvestigationAssignment::where('investigation_id', $inv->id)->count();
        $this->assertSame(2, $totalAssignments, 'Both assignments must be preserved — append-only');
    }

    public function test_assignment_appends_event(): void
    {
        $inv = $this->makeInvestigation();
        $this->orchestrator->assignAnalyst($inv->id, $this->analyst->id, $this->analyst->id);

        $event = InvestigationEvent::where('investigation_id', $inv->id)
            ->where('event_type', 'assignment')
            ->first();

        $this->assertNotNull($event);
    }

    // -------------------------------------------------------------------------
    // Notes — append-only
    // -------------------------------------------------------------------------

    public function test_add_note_creates_note_record(): void
    {
        $inv  = $this->makeInvestigation();
        $note = $this->orchestrator->addNote($inv->id, $this->analyst->id, 'triage', 'Looks suspicious.');

        $this->assertDatabaseHas('investigation_notes', [
            'investigation_id' => $inv->id,
            'note_type'        => 'triage',
            'body'             => 'Looks suspicious.',
        ]);
    }

    public function test_add_note_appends_event(): void
    {
        $inv = $this->makeInvestigation();
        $this->orchestrator->addNote($inv->id, $this->analyst->id, 'evidence', 'Evidence note.');

        $event = InvestigationEvent::where('investigation_id', $inv->id)
            ->where('event_type', 'note_added')
            ->first();

        $this->assertNotNull($event);
    }

    public function test_multiple_notes_are_all_preserved(): void
    {
        $inv = $this->makeInvestigation();

        for ($i = 1; $i <= 5; $i++) {
            $this->orchestrator->addNote($inv->id, $this->analyst->id, 'general', "Note {$i}");
        }

        $this->assertSame(5, InvestigationNote::where('investigation_id', $inv->id)->count());
    }

    // -------------------------------------------------------------------------
    // Artifacts
    // -------------------------------------------------------------------------

    public function test_add_artifact_creates_artifact_record(): void
    {
        $inv = $this->makeInvestigation();
        $this->orchestrator->addArtifact($inv->id, $this->analyst->id, [
            'artifact_type' => 'external_ticket',
            'title'         => 'JIRA-1234',
            'reference'     => 'https://jira.example.com/browse/JIRA-1234',
        ]);

        $this->assertDatabaseHas('investigation_artifacts', [
            'investigation_id' => $inv->id,
            'artifact_type'    => 'external_ticket',
            'title'            => 'JIRA-1234',
        ]);
    }

    public function test_artifact_appends_event(): void
    {
        $inv = $this->makeInvestigation();
        $this->orchestrator->addArtifact($inv->id, $this->analyst->id, [
            'artifact_type' => 'case_reference',
            'title'         => 'Case REF-001',
        ]);

        $event = InvestigationEvent::where('investigation_id', $inv->id)
            ->where('event_type', 'artifact_added')
            ->first();

        $this->assertNotNull($event);
    }

    // -------------------------------------------------------------------------
    // Timeline — append-only
    // -------------------------------------------------------------------------

    public function test_get_timeline_returns_ordered_events(): void
    {
        $inv = $this->makeInvestigation();
        $this->orchestrator->transitionState($inv->id, 'triaged', $this->analyst->id);
        $this->orchestrator->addNote($inv->id, $this->analyst->id, 'general', 'Test note.');

        $timeline = $this->orchestrator->getTimeline($inv->id);

        $this->assertGreaterThanOrEqual(3, $timeline->count()); // created + transition + note
        $times    = $timeline->pluck('occurred_at')->toArray();
        $sortedTimes = $times;
        sort($sortedTimes);
        $this->assertSame($sortedTimes, $times, 'Timeline must be in ascending occurred_at order');
    }

    public function test_events_are_never_deleted_only_appended(): void
    {
        $inv = $this->makeInvestigation();

        $this->orchestrator->transitionState($inv->id, 'triaged', $this->analyst->id);
        $countAfterFirst = InvestigationEvent::where('investigation_id', $inv->id)->count();

        $this->orchestrator->transitionState($inv->id, 'investigating', $this->analyst->id);
        $countAfterSecond = InvestigationEvent::where('investigation_id', $inv->id)->count();

        $this->assertGreaterThan($countAfterFirst, $countAfterSecond,
            'Event count must only grow — events are append-only');
    }

    // -------------------------------------------------------------------------
    // No automated enforcement
    // -------------------------------------------------------------------------

    public function test_contained_manual_stores_documentation_only(): void
    {
        $inv = $this->makeInvestigation('investigating');

        $alertCountBefore    = DB::table('security_alerts')->count();
        $incidentCountBefore = DB::table('security_incidents')->count();

        $this->orchestrator->transitionState($inv->id, 'contained_manual', $this->analyst->id,
            'Manual containment documented by analyst.');

        $this->assertSame('contained_manual', Investigation::find($inv->id)->state);
        $this->assertSame($alertCountBefore, DB::table('security_alerts')->count(),
            'contained_manual must not touch security_alerts');
        $this->assertSame($incidentCountBefore, DB::table('security_incidents')->count(),
            'contained_manual must not touch security_incidents');
    }

    public function test_no_automated_enforcement_in_any_state_transition(): void
    {
        $transitions = [
            ['from' => 'new',           'to' => 'triaged'],
            ['from' => 'triaged',       'to' => 'investigating'],
            ['from' => 'investigating', 'to' => 'escalated'],
            ['from' => 'escalated',     'to' => 'resolved'],
        ];

        foreach ($transitions as $t) {
            $inv = $this->makeInvestigation($t['from']);

            $alertsBefore    = DB::table('security_alerts')->count();
            $incidentsBefore = DB::table('security_incidents')->count();

            $this->orchestrator->transitionState($inv->id, $t['to'], $this->analyst->id);

            $this->assertSame($alertsBefore, DB::table('security_alerts')->count(),
                "Transition {$t['from']}→{$t['to']} must not modify security_alerts");
            $this->assertSame($incidentsBefore, DB::table('security_incidents')->count(),
                "Transition {$t['from']}→{$t['to']} must not modify security_incidents");
        }
    }

    public function test_result_has_no_enforcement_or_containment_keys(): void
    {
        $inv = $this->makeInvestigation();
        $invArray = (array) DB::table('investigations')->where('id', $inv->id)->first();

        $forbidden = ['block', 'quarantine', 'isolate', 'terminate', 'kill', 'containment_command'];
        foreach ($forbidden as $key) {
            $this->assertArrayNotHasKey($key, $invArray);
        }
    }

    // -------------------------------------------------------------------------
    // RBAC / authorization
    // -------------------------------------------------------------------------

    public function test_api_investigation_index_requires_authentication(): void
    {
        $this->get('/api/investigations')->assertRedirect();
    }

    public function test_api_investigation_show_requires_authentication(): void
    {
        $this->get('/api/investigations/1')->assertRedirect();
    }

    public function test_api_investigation_timeline_requires_authentication(): void
    {
        $this->get('/api/investigations/1/timeline')->assertRedirect();
    }

    public function test_api_investigation_notes_requires_authentication(): void
    {
        $this->get('/api/investigations/1/notes')->assertRedirect();
    }

    public function test_api_investigation_post_state_requires_authentication(): void
    {
        $this->postJson('/api/investigations/1/state', ['state' => 'triaged'])->assertUnauthorized();
    }

    public function test_viewer_can_view_investigations(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->get('/api/investigations')
            ->assertOk()
            ->assertJsonStructure(['investigations', 'total', 'counts']);
    }

    public function test_viewer_cannot_create_investigation_via_web(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->post('/investigations', [
            'title'    => 'Unauthorized',
            'severity' => 'high',
        ])->assertForbidden();
    }

    public function test_analyst_can_create_investigation(): void
    {
        $response = $this->actingAs($this->analyst)->post('/investigations', [
            'title'    => 'Analyst Investigation',
            'severity' => 'high',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('investigations', ['title' => 'Analyst Investigation']);
    }

    public function test_investigation_dashboard_requires_auth(): void
    {
        $this->get('/investigations')->assertRedirect();
    }

    public function test_investigation_dashboard_returns_200_for_analyst(): void
    {
        $this->actingAs($this->analyst)->get('/investigations')->assertOk();
    }

    public function test_api_investigation_index_returns_json_for_analyst(): void
    {
        $this->actingAs($this->analyst)->get('/api/investigations')
            ->assertOk()
            ->assertJsonStructure(['investigations', 'total', 'counts']);
    }

    public function test_api_investigation_show_returns_404_for_missing(): void
    {
        $this->actingAs($this->analyst)->get('/api/investigations/99999')
            ->assertNotFound()
            ->assertJson(['error' => 'investigation_not_found']);
    }

    public function test_api_investigation_post_note_creates_note(): void
    {
        $inv = $this->makeInvestigation();

        $this->actingAs($this->analyst)->postJson("/api/investigations/{$inv->id}/notes", [
            'note_type' => 'triage',
            'body'      => 'API-created triage note.',
        ])->assertStatus(201);

        $this->assertDatabaseHas('investigation_notes', [
            'investigation_id' => $inv->id,
            'body'             => 'API-created triage note.',
        ]);
    }

    public function test_api_investigation_post_state_transitions_state(): void
    {
        $inv = $this->makeInvestigation();

        $this->actingAs($this->analyst)->postJson("/api/investigations/{$inv->id}/state", [
            'state' => 'triaged',
        ])->assertOk()->assertJson(['status' => 'transitioned', 'new_state' => 'triaged']);

        $this->assertSame('triaged', Investigation::find($inv->id)->state);
    }

    public function test_api_investigation_post_invalid_state_returns_422(): void
    {
        $inv = $this->makeInvestigation(); // state = 'new'

        $this->actingAs($this->analyst)->postJson("/api/investigations/{$inv->id}/state", [
            'state' => 'resolved', // not valid from 'new'
        ])->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Audit trail
    // -------------------------------------------------------------------------

    public function test_audit_trail_captures_all_workflow_changes(): void
    {
        $analyst2 = User::factory()->create(['role' => 'analyst']);
        $inv      = $this->makeInvestigation();

        $this->orchestrator->transitionState($inv->id, 'triaged', $this->analyst->id, 'Initial triage');
        $this->orchestrator->assignAnalyst($inv->id, $analyst2->id, $this->analyst->id);
        $this->orchestrator->addNote($inv->id, $this->analyst->id, 'evidence', 'Observed suspicious activity');
        $this->orchestrator->addArtifact($inv->id, $this->analyst->id, [
            'artifact_type' => 'related_url',
            'title'         => 'Related alert dashboard',
        ]);
        $this->orchestrator->transitionState($inv->id, 'investigating', $analyst2->id);

        $timeline  = $this->orchestrator->getTimeline($inv->id);
        $eventTypes = $timeline->pluck('event_type')->toArray();

        $this->assertContains('created',          $eventTypes);
        $this->assertContains('state_transition',  $eventTypes);
        $this->assertContains('assignment',        $eventTypes);
        $this->assertContains('note_added',        $eventTypes);
        $this->assertContains('artifact_added',    $eventTypes);
    }

    // -------------------------------------------------------------------------
    // Grafana dashboard
    // -------------------------------------------------------------------------

    public function test_investigation_grafana_dashboard_exists(): void
    {
        $path = base_path('docs/grafana/investigation-workflow-dashboard.json');
        $this->assertFileExists($path);

        $json = json_decode(file_get_contents($path), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('panels', $json);
        $this->assertNotEmpty($json['panels']);
        $this->assertSame('XDR Investigation Workflow Dashboard', $json['title']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeInvestigation(string $state = 'new'): Investigation
    {
        $inv = $this->orchestrator->createInvestigation([
            'title'    => 'Test Investigation',
            'severity' => 'high',
        ], $this->analyst->id);

        if ($state !== 'new') {
            // Force-set state directly to avoid transition chain in tests
            DB::table('investigations')->where('id', $inv->id)->update(['state' => $state]);
            $inv->state = $state;
        }

        return $inv;
    }
}
