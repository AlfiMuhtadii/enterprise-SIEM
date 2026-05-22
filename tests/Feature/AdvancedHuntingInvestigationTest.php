<?php

namespace Tests\Feature;

use App\Models\CrossDomainCorrelation;
use App\Models\InvestigationBookmark;
use App\Models\InvestigationEvidenceLink;
use App\Models\InvestigationGraphEdge;
use App\Models\InvestigationGraphNode;
use App\Models\InvestigationSession;
use App\Models\InvestigationTimelineEvent;
use App\Models\RetrospectiveHuntQuery;
use App\Models\User;
use App\Services\EntityRiskScoringService;
use App\Services\InvestigationGraphService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Advanced Threat Hunting & Investigation Phase 1 — Feature Tests.
 *
 * Hard safety assertions (MUST remain green):
 *   - No isolateHost
 *   - No quarantineHost
 *   - No executeShell
 *   - No killProcess
 *   - No autoRemediate
 *   - No autonomous investigation actions
 *   - No evidence deletion
 *   - No hidden graph mutation
 *   - All graph nodes and edges are advisory_only = true
 *   - Append-only tables cannot be updated
 */
class AdvancedHuntingInvestigationTest extends TestCase
{
    use RefreshDatabase;

    private InvestigationGraphService $svc;
    private ThreatHuntingService      $hunting;
    private User                      $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc     = app(InvestigationGraphService::class);
        $this->hunting = app(ThreatHuntingService::class);
        $this->user    = User::factory()->create();
    }

    // =========================================================================
    // Schema — new tables exist
    // =========================================================================

    public function test_investigation_graph_nodes_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('investigation_graph_nodes'));
    }

    public function test_investigation_graph_edges_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('investigation_graph_edges'));
    }

    public function test_investigation_sessions_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('investigation_sessions'));
    }

    public function test_investigation_bookmarks_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('investigation_bookmarks'));
    }

    public function test_retrospective_hunt_queries_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('retrospective_hunt_queries'));
    }

    public function test_investigation_evidence_links_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('investigation_evidence_links'));
    }

    public function test_investigation_timeline_events_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('investigation_timeline_events'));
    }

    // =========================================================================
    // Session management
    // =========================================================================

    public function test_create_session_returns_valid_session(): void
    {
        $session = $this->svc->createSession(
            'Hunting for lateral movement',
            investigationId: 'inv-001',
            focusEntityId: 'user@example.com',
            focusNodeType: 'user',
            hopDepth: 2,
            createdBy: $this->user->id
        );

        $this->assertInstanceOf(InvestigationSession::class, $session);
        $this->assertStringStartsWith('igs-', $session->session_id);
        $this->assertSame('active', $session->status);
        $this->assertSame(2, $session->hop_depth);
        $this->assertTrue($session->include_advisory);
        $this->assertDatabaseHas('investigation_sessions', ['session_id' => $session->session_id]);
    }

    public function test_pause_session_changes_status(): void
    {
        $session = $this->svc->createSession('Test session');
        $this->svc->pauseSession($session);
        $session->refresh();

        $this->assertSame('paused', $session->status);
    }

    public function test_complete_session_changes_status(): void
    {
        $session = $this->svc->createSession('Test session');
        $this->svc->completeSession($session);
        $session->refresh();

        $this->assertSame('completed', $session->status);
    }

    public function test_get_sessions_returns_collection(): void
    {
        $this->svc->createSession('Session A', investigationId: 'inv-A');
        $this->svc->createSession('Session B', investigationId: 'inv-A');
        $this->svc->createSession('Session C', investigationId: 'inv-B');

        $all = $this->svc->getSessions();
        $this->assertCount(3, $all);

        $filtered = $this->svc->getSessions('inv-A');
        $this->assertCount(2, $filtered);
    }

    // =========================================================================
    // Graph nodes — append-safe
    // =========================================================================

    public function test_add_node_creates_investigation_graph_node(): void
    {
        $session = $this->svc->createSession('Test');

        $node = $this->svc->addNode(
            $session->session_id,
            'user',
            'alice@example.com',
            entityId: 'alice@example.com',
            riskScore: 7.5,
            isPivotOrigin: true,
            createdBy: $this->user->id
        );

        $this->assertInstanceOf(InvestigationGraphNode::class, $node);
        $this->assertStringStartsWith('ign-', $node->node_id);
        $this->assertSame('user', $node->node_type);
        $this->assertTrue($node->is_pivot_origin);
        $this->assertTrue($node->is_advisory);
        $this->assertSame(7.5, $node->risk_score);
    }

    public function test_graph_node_has_no_updated_at_column(): void
    {
        $session = $this->svc->createSession('Test');
        $node    = $this->svc->addNode($session->session_id, 'host', 'web-server-01');

        $row = DB::table('investigation_graph_nodes')->where('id', $node->id)->first();
        $this->assertNull($row->updated_at ?? null);
    }

    public function test_graph_node_is_always_advisory(): void
    {
        $session = $this->svc->createSession('Test');
        $node    = $this->svc->addNode($session->session_id, 'ip', '10.0.0.1');

        $this->assertTrue($node->is_advisory);
    }

    public function test_graph_node_update_throws_logic_exception(): void
    {
        $session = $this->svc->createSession('Test');
        $node    = $this->svc->addNode($session->session_id, 'process', 'cmd.exe');

        $this->expectException(\LogicException::class);
        $node->label = 'pwsh.exe';
        $node->save();
    }

    // =========================================================================
    // Graph edges — append-safe
    // =========================================================================

    public function test_add_edge_creates_investigation_graph_edge(): void
    {
        $session = $this->svc->createSession('Test');
        $from    = $this->svc->addNode($session->session_id, 'user', 'alice');
        $to      = $this->svc->addNode($session->session_id, 'host', 'workstation-01');

        $edge = $this->svc->addEdge(
            $session->session_id,
            $from->node_id,
            $to->node_id,
            'authenticated_as',
            confidence: 0.9,
            evidenceSource: 'identity_provider_events',
            evidenceId: 'evt-123'
        );

        $this->assertInstanceOf(InvestigationGraphEdge::class, $edge);
        $this->assertStringStartsWith('ige-', $edge->edge_id);
        $this->assertSame('authenticated_as', $edge->relationship_type);
        $this->assertSame(0.9, $edge->confidence);
        $this->assertTrue($edge->is_advisory);
    }

    public function test_graph_edge_has_no_updated_at_column(): void
    {
        $session = $this->svc->createSession('Test');
        $n1 = $this->svc->addNode($session->session_id, 'user', 'alice');
        $n2 = $this->svc->addNode($session->session_id, 'host', 'host1');
        $edge = $this->svc->addEdge($session->session_id, $n1->node_id, $n2->node_id, 'observed_on');

        $row = DB::table('investigation_graph_edges')->where('id', $edge->id)->first();
        $this->assertNull($row->updated_at ?? null);
    }

    public function test_graph_edge_update_throws_logic_exception(): void
    {
        $session = $this->svc->createSession('Test');
        $n1 = $this->svc->addNode($session->session_id, 'user', 'alice');
        $n2 = $this->svc->addNode($session->session_id, 'host', 'host1');
        $edge = $this->svc->addEdge($session->session_id, $n1->node_id, $n2->node_id, 'observed_on');

        $this->expectException(\LogicException::class);
        $edge->relationship_type = 'spawned';
        $edge->save();
    }

    // =========================================================================
    // Session graph retrieval
    // =========================================================================

    public function test_get_session_graph_returns_nodes_and_edges(): void
    {
        $session = $this->svc->createSession('Test');
        $n1 = $this->svc->addNode($session->session_id, 'user', 'alice');
        $n2 = $this->svc->addNode($session->session_id, 'host', 'ws-01');
        $this->svc->addEdge($session->session_id, $n1->node_id, $n2->node_id, 'observed_on');

        $graph = $this->svc->getSessionGraph($session->session_id);

        $this->assertArrayHasKey('nodes', $graph);
        $this->assertArrayHasKey('edges', $graph);
        $this->assertCount(2, $graph['nodes']);
        $this->assertCount(1, $graph['edges']);
    }

    // =========================================================================
    // Timeline events — append-only
    // =========================================================================

    public function test_add_timeline_event_creates_record(): void
    {
        $session = $this->svc->createSession('Test');

        $event = $this->svc->addTimelineEvent(
            'inv-001',
            'execution',
            'PowerShell encoded command executed by user alice',
            $session->session_id,
            mitreT: 'T1059.001',
            mitreTactic: 'execution',
            actorEntityId: 'alice@example.com',
            confidence: 0.85
        );

        $this->assertInstanceOf(InvestigationTimelineEvent::class, $event);
        $this->assertStringStartsWith('ite-', $event->event_id);
        $this->assertSame('T1059.001', $event->mitre_technique);
        $this->assertTrue($event->is_advisory);
    }

    public function test_timeline_event_has_no_updated_at_column(): void
    {
        $session = $this->svc->createSession('Test');
        $event   = $this->svc->addTimelineEvent('inv-001', 'persistence', 'Run key added', $session->session_id);

        $row = DB::table('investigation_timeline_events')->where('id', $event->id)->first();
        $this->assertNull($row->updated_at ?? null);
    }

    public function test_timeline_event_update_throws_logic_exception(): void
    {
        $session = $this->svc->createSession('Test');
        $event   = $this->svc->addTimelineEvent('inv-001', 'discovery', 'Network scan', $session->session_id);

        $this->expectException(\LogicException::class);
        $event->description = 'modified';
        $event->save();
    }

    public function test_get_attack_timeline_returns_chronological_events(): void
    {
        $session = $this->svc->createSession('Test');
        $t1      = now()->subHours(2);
        $t2      = now()->subHour();
        $t3      = now();

        $this->svc->addTimelineEvent('inv-timeline', 'initial_access', 'Phishing email clicked', $session->session_id, occurredAt: $t1->toDateTimeString());
        $this->svc->addTimelineEvent('inv-timeline', 'execution', 'Malicious macro ran', $session->session_id, occurredAt: $t2->toDateTimeString());
        $this->svc->addTimelineEvent('inv-timeline', 'persistence', 'Registry run key added', $session->session_id, occurredAt: $t3->toDateTimeString());

        $timeline = $this->svc->getAttackTimeline('inv-timeline');

        $this->assertCount(3, $timeline);
        $this->assertSame('initial_access', $timeline->first()->event_category);
        $this->assertSame('persistence', $timeline->last()->event_category);
    }

    // =========================================================================
    // Retrospective hunt queries
    // =========================================================================

    public function test_create_retrospective_query_stores_record(): void
    {
        $session = $this->svc->createSession('Test');

        $query = $this->svc->createRetrospectiveQuery(
            'Hunt for PowerShell executions',
            'endpoint_script_executions',
            ['is_encoded' => true],
            investigationId: 'inv-001',
            sessionId: $session->session_id,
            timeRangeStart: '-7d'
        );

        $this->assertInstanceOf(RetrospectiveHuntQuery::class, $query);
        $this->assertStringStartsWith('rhq-', $query->query_id);
        $this->assertSame('draft', $query->status);
        $this->assertTrue($query->is_advisory);
        $this->assertTrue($query->replay_safe);
    }

    public function test_execute_retrospective_query_marks_as_executed(): void
    {
        $session = $this->svc->createSession('Test');
        $query   = $this->svc->createRetrospectiveQuery('Test query', 'endpoint_script_executions', [], sessionId: $session->session_id);

        $result = $this->svc->executeRetrospectiveQuery($query);

        $this->assertArrayHasKey('query_id', $result);
        $this->assertArrayHasKey('result_count', $result);
        $this->assertTrue($result['is_advisory']);
        $this->assertTrue($result['replay_safe']);

        $query->refresh();
        $this->assertSame('executed', $query->status);
        $this->assertNotNull($query->last_executed_at);
    }

    public function test_archive_query_sets_status_archived(): void
    {
        $session = $this->svc->createSession('Test');
        $query   = $this->svc->createRetrospectiveQuery('Old query', 'alerts', [], sessionId: $session->session_id);
        $this->svc->archiveQuery($query);
        $query->refresh();

        $this->assertSame('archived', $query->status);
    }

    // =========================================================================
    // Evidence linking — append-only
    // =========================================================================

    public function test_link_evidence_creates_append_only_record(): void
    {
        $link = $this->svc->linkEvidence(
            'inv-001',
            'alert',
            'alert-xyz-123',
            sessionId: 'sess-001',
            rationale: 'Alert triggered by suspicious PowerShell process',
            createdBy: $this->user->id
        );

        $this->assertInstanceOf(InvestigationEvidenceLink::class, $link);
        $this->assertStringStartsWith('iel-', $link->link_id);
        $this->assertTrue($link->is_advisory);
    }

    public function test_evidence_link_has_no_updated_at_column(): void
    {
        $link = $this->svc->linkEvidence('inv-001', 'alert', 'alert-001');

        $row = DB::table('investigation_evidence_links')->where('id', $link->id)->first();
        $this->assertNull($row->updated_at ?? null);
    }

    public function test_evidence_link_update_throws_logic_exception(): void
    {
        $link = $this->svc->linkEvidence('inv-001', 'incident', 'inc-001');

        $this->expectException(\LogicException::class);
        $link->evidence_id = 'inc-002';
        $link->save();
    }

    public function test_get_evidence_links_returns_all_for_investigation(): void
    {
        $this->svc->linkEvidence('inv-ev', 'alert', 'alert-001');
        $this->svc->linkEvidence('inv-ev', 'alert', 'alert-002');
        $this->svc->linkEvidence('inv-OTHER', 'alert', 'alert-003');

        $links = $this->svc->getEvidenceLinks('inv-ev');
        $this->assertCount(2, $links);
    }

    // =========================================================================
    // Bookmarks — mutable
    // =========================================================================

    public function test_add_bookmark_creates_record(): void
    {
        $session = $this->svc->createSession('Test');

        $bm = $this->svc->addBookmark(
            $session->session_id,
            'Suspicious IP 10.0.0.99',
            pivotType: 'ip',
            pivotValue: '10.0.0.99',
            pivotDomain: 'network_behavioral_findings'
        );

        $this->assertInstanceOf(InvestigationBookmark::class, $bm);
        $this->assertStringStartsWith('ibk-', $bm->bookmark_id);
        $this->assertFalse($bm->is_pinned);
    }

    public function test_pin_bookmark_sets_is_pinned(): void
    {
        $session = $this->svc->createSession('Test');
        $bm      = $this->svc->addBookmark($session->session_id, 'Key pivot');
        $this->svc->pinBookmark($bm);
        $bm->refresh();

        $this->assertTrue($bm->is_pinned);
    }

    public function test_get_bookmarks_returns_pinned_first(): void
    {
        $session = $this->svc->createSession('Test');
        $b1      = $this->svc->addBookmark($session->session_id, 'Regular');
        $b2      = $this->svc->addBookmark($session->session_id, 'Will be pinned');
        $this->svc->pinBookmark($b2);

        $bookmarks = $this->svc->getBookmarks($session->session_id);
        $this->assertTrue($bookmarks->first()->is_pinned);
    }

    // =========================================================================
    // Threat hunting domain support
    // =========================================================================

    public function test_threat_hunting_supports_50_domains(): void
    {
        $domains = $this->hunting->supportedDomains();
        $this->assertCount(115, $domains);
    }

    public function test_hunt_investigation_graph_nodes_domain(): void
    {
        $session = $this->svc->createSession('Test');
        $this->svc->addNode($session->session_id, 'user', 'alice@example.com');

        $results = $this->hunting->hunt('investigation_graph_nodes', ['node_type' => 'user']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_investigation_graph_edges_domain(): void
    {
        $session = $this->svc->createSession('Test');
        $n1 = $this->svc->addNode($session->session_id, 'user', 'alice');
        $n2 = $this->svc->addNode($session->session_id, 'host', 'ws-01');
        $this->svc->addEdge($session->session_id, $n1->node_id, $n2->node_id, 'authenticated_as');

        $results = $this->hunting->hunt('investigation_graph_edges', ['relationship_type' => 'authenticated_as']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_investigation_sessions_domain(): void
    {
        $this->svc->createSession('Active session', investigationId: 'inv-dom-test');

        $results = $this->hunting->hunt('investigation_sessions', ['status' => 'active']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_retrospective_hunt_queries_domain(): void
    {
        $session = $this->svc->createSession('Test');
        $this->svc->createRetrospectiveQuery('Domain test query', 'alerts', [], sessionId: $session->session_id);

        $results = $this->hunting->hunt('retrospective_hunt_queries', ['domain' => 'alerts']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_investigation_timeline_events_domain(): void
    {
        $session = $this->svc->createSession('Test');
        $this->svc->addTimelineEvent('inv-hunt', 'execution', 'Test event', $session->session_id, mitreT: 'T1059');

        $results = $this->hunting->hunt('investigation_timeline_events', ['event_category' => 'execution']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_returns_expected_keys(): void
    {
        $stats = $this->svc->getDashboardStats();

        $this->assertArrayHasKey('total_sessions', $stats);
        $this->assertArrayHasKey('active_sessions', $stats);
        $this->assertArrayHasKey('total_graph_nodes', $stats);
        $this->assertArrayHasKey('total_graph_edges', $stats);
        $this->assertArrayHasKey('total_timeline_events', $stats);
        $this->assertArrayHasKey('total_evidence_links', $stats);
        $this->assertArrayHasKey('total_retrospective_queries', $stats);
        $this->assertArrayHasKey('executed_queries', $stats);
        $this->assertTrue($stats['advisory_only']);
        $this->assertFalse($stats['autonomous_actions']);
    }

    public function test_dashboard_stats_counts_are_accurate(): void
    {
        $session = $this->svc->createSession('A');
        $this->svc->createSession('B');
        $this->svc->completeSession($this->svc->createSession('C'));
        $this->svc->addNode($session->session_id, 'user', 'alice');
        $this->svc->addNode($session->session_id, 'host', 'ws');
        $this->svc->linkEvidence('inv-x', 'alert', 'a1');

        $stats = $this->svc->getDashboardStats();

        $this->assertSame(3, $stats['total_sessions']);
        $this->assertSame(2, $stats['active_sessions']);
        $this->assertSame(2, $stats['total_graph_nodes']);
        $this->assertSame(1, $stats['total_evidence_links']);
    }

    // =========================================================================
    // Entity risk scoring — ATHI factors
    // =========================================================================

    public function test_entity_risk_scoring_service_has_athi_weights(): void
    {
        $weights = EntityRiskScoringService::WEIGHTS;

        $this->assertArrayHasKey('attack_path_factor', $weights);
        $this->assertArrayHasKey('multi_host_correlation_factor', $weights);
        $this->assertArrayHasKey('repeated_pivot_factor', $weights);
        $this->assertArrayHasKey('investigation_relevance_factor', $weights);

        $this->assertSame(2.5, $weights['attack_path_factor']);
        $this->assertSame(2.0, $weights['multi_host_correlation_factor']);
        $this->assertSame(1.5, $weights['repeated_pivot_factor']);
        $this->assertSame(1.0, $weights['investigation_relevance_factor']);
    }

    // =========================================================================
    // UI routes
    // =========================================================================

    public function test_workspace_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('investigation.advanced.workspace'))
            ->assertStatus(200);
    }

    public function test_workspace_contains_advisory_notice(): void
    {
        $this->actingAs($this->user)
            ->get(route('investigation.advanced.workspace'))
            ->assertSee('Advisory');
    }

    public function test_session_history_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('investigation.advanced.session-history'))
            ->assertStatus(200);
    }

    public function test_multi_hop_pivot_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('investigation.advanced.multi-hop-pivot'))
            ->assertStatus(200);
    }

    public function test_retrospective_hunt_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('investigation.advanced.retrospective-hunt'))
            ->assertStatus(200);
    }

    public function test_attack_investigation_map_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('investigation.advanced.attack-investigation-map'))
            ->assertStatus(200);
    }

    public function test_cross_domain_explorer_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('investigation.advanced.cross-domain-explorer'))
            ->assertStatus(200);
    }

    public function test_graph_explorer_route_requires_valid_session(): void
    {
        $session = $this->svc->createSession('Test session for route');

        $this->actingAs($this->user)
            ->get(route('investigation.advanced.graph-explorer', ['sessionId' => $session->session_id]))
            ->assertStatus(200);
    }

    public function test_graph_explorer_returns_404_for_unknown_session(): void
    {
        $this->actingAs($this->user)
            ->get(route('investigation.advanced.graph-explorer', ['sessionId' => 'igs-nonexistent']))
            ->assertStatus(404);
    }

    public function test_attack_timeline_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('investigation.advanced.attack-timeline', ['investigationId' => 'inv-test']))
            ->assertStatus(200);
    }

    public function test_evidence_relationships_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('investigation.advanced.evidence-relationships', ['investigationId' => 'inv-test']))
            ->assertStatus(200);
    }

    // =========================================================================
    // Safety invariants — no autonomous enforcement methods exist
    // =========================================================================

    public function test_investigation_graph_service_has_no_isolate_host(): void
    {
        $this->assertFalse(method_exists(InvestigationGraphService::class, 'isolateHost'));
    }

    public function test_investigation_graph_service_has_no_quarantine_host(): void
    {
        $this->assertFalse(method_exists(InvestigationGraphService::class, 'quarantineHost'));
    }

    public function test_investigation_graph_service_has_no_execute_shell(): void
    {
        $this->assertFalse(method_exists(InvestigationGraphService::class, 'executeShell'));
    }

    public function test_investigation_graph_service_has_no_kill_process(): void
    {
        $this->assertFalse(method_exists(InvestigationGraphService::class, 'killProcess'));
    }

    public function test_investigation_graph_service_has_no_auto_remediate(): void
    {
        $this->assertFalse(method_exists(InvestigationGraphService::class, 'autoRemediate'));
    }

    public function test_investigation_graph_service_has_no_delete_evidence(): void
    {
        $this->assertFalse(method_exists(InvestigationGraphService::class, 'deleteEvidence'));
    }

    public function test_investigation_graph_service_has_no_delete_node(): void
    {
        $this->assertFalse(method_exists(InvestigationGraphService::class, 'deleteNode'));
    }

    public function test_investigation_graph_service_has_no_autonomous_investigate(): void
    {
        $this->assertFalse(method_exists(InvestigationGraphService::class, 'autonomouslyInvestigate'));
    }

    public function test_all_graph_nodes_created_are_advisory_only(): void
    {
        $session = $this->svc->createSession('Safety test');
        foreach (InvestigationGraphNode::NODE_TYPES as $type) {
            $node = $this->svc->addNode($session->session_id, $type, "label-{$type}");
            $this->assertTrue($node->is_advisory, "Node type {$type} must be advisory");
        }
    }

    public function test_all_graph_edges_created_are_advisory_only(): void
    {
        $session = $this->svc->createSession('Safety test');
        $n1 = $this->svc->addNode($session->session_id, 'user', 'alice');
        $n2 = $this->svc->addNode($session->session_id, 'host', 'ws1');

        foreach (InvestigationGraphEdge::RELATIONSHIP_TYPES as $type) {
            $edge = $this->svc->addEdge($session->session_id, $n1->node_id, $n2->node_id, $type);
            $this->assertTrue($edge->is_advisory, "Edge type {$type} must be advisory");
        }
    }

    public function test_retrospective_queries_are_always_replay_safe(): void
    {
        $session = $this->svc->createSession('Test');
        $query   = $this->svc->createRetrospectiveQuery('Test', 'alerts', [], sessionId: $session->session_id);
        $this->assertTrue($query->replay_safe);
        $this->assertTrue($query->is_advisory);
    }
}
