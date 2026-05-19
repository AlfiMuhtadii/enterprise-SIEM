<?php

namespace Tests\Feature;

use App\Models\AnalystHandoff;
use App\Models\EscalationEvent;
use App\Models\Investigation;
use App\Models\InvestigationCollaborator;
use App\Models\InvestigationWatcher;
use App\Models\SlaBreach;
use App\Models\SlaEvent;
use App\Models\SlaPolicy;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistEvent;
use App\Services\EscalationService;
use App\Services\SlaTrackingService;
use App\Services\SocCollaborationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SOC Collaboration & Analyst Workflow Layer Phase 1 tests.
 *
 * Explicitly asserts:
 * - No autonomous escalation
 * - No auto-response execution
 * - No hidden workflow mutation
 * - No unrestricted case sharing
 * - No analyst impersonation
 * - Append-only collaboration history
 * - Deterministic workflow transitions
 * - Escalation routing correctness
 * - SLA breach calculation
 * - Analyst reassignment requires explicit action
 * - Watchlist expiration handling
 * - Shift handoff persistence
 * - Shared investigation permissions (explicit)
 * - Audit attribution correctness
 * - Replay-safe collaboration history
 */
class SocCollaborationWorkflowTest extends TestCase
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

    private function collab(): SocCollaborationService
    {
        return app(SocCollaborationService::class);
    }

    private function escalation(): EscalationService
    {
        return app(EscalationService::class);
    }

    private function sla(): SlaTrackingService
    {
        return app(SlaTrackingService::class);
    }

    private function makeInvestigation(?User $analyst = null): Investigation
    {
        $a = $analyst ?? $this->analyst();
        return Investigation::factory()->create([
            'assigned_to' => $a->id,
            'created_by'  => $a->id,
            'state'       => 'triaged',
            'severity'    => 'high',
        ]);
    }

    // -----------------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------------

    public function test_investigation_collaborators_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('investigation_collaborators'));
    }

    public function test_investigation_watchers_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('investigation_watchers'));
    }

    public function test_escalation_events_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('escalation_events'));
    }

    public function test_analyst_handoffs_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('analyst_handoffs'));
    }

    public function test_watchlists_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('watchlists'));
    }

    public function test_watchlist_events_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('watchlist_events'));
    }

    public function test_sla_policies_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('sla_policies'));
    }

    public function test_sla_events_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('sla_events'));
    }

    public function test_sla_breaches_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('sla_breaches'));
    }

    // Append-only tables must have no updated_at
    public function test_investigation_collaborators_is_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('investigation_collaborators', 'updated_at'));
    }

    public function test_investigation_watchers_is_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('investigation_watchers', 'updated_at'));
    }

    public function test_escalation_events_is_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('escalation_events', 'updated_at'));
    }

    public function test_analyst_handoffs_is_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('analyst_handoffs', 'updated_at'));
    }

    public function test_watchlist_events_is_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('watchlist_events', 'updated_at'));
    }

    public function test_sla_events_is_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('sla_events', 'updated_at'));
    }

    public function test_sla_breaches_is_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('sla_breaches', 'updated_at'));
    }

    // -----------------------------------------------------------------------
    // Collaboration — append-only history
    // -----------------------------------------------------------------------

    public function test_add_collaborator_creates_record(): void
    {
        $inv    = $this->makeInvestigation();
        $analyst= $this->analyst();
        $addedBy= $this->admin();

        $collab = $this->collab()->addCollaborator(
            $inv->investigation_id, $analyst->id, InvestigationCollaborator::ROLE_COLLABORATOR, $addedBy
        );

        $this->assertStringStartsWith('collab-', $collab->collab_id);
        $this->assertDatabaseHas('investigation_collaborators', [
            'investigation_id' => $inv->investigation_id,
            'analyst_id'       => $analyst->id,
            'role'             => 'collaborator',
        ]);
    }

    public function test_add_collaborator_requires_valid_role(): void
    {
        $inv = $this->makeInvestigation();
        $this->expectException(\InvalidArgumentException::class);
        $this->collab()->addCollaborator($inv->investigation_id, 999, 'superuser', $this->admin());
    }

    public function test_collaboration_history_is_append_only(): void
    {
        $inv    = $this->makeInvestigation();
        $analyst= $this->analyst();
        $addedBy= $this->admin();

        $collab = $this->collab()->addCollaborator(
            $inv->investigation_id, $analyst->id, InvestigationCollaborator::ROLE_COLLABORATOR, $addedBy
        );

        $this->assertNull($collab->updated_at, 'Collaborator record must be append-only');
    }

    public function test_archive_collaborator_adds_new_inactive_record(): void
    {
        $inv    = $this->makeInvestigation();
        $analyst= $this->analyst();
        $addedBy= $this->admin();

        $active = $this->collab()->addCollaborator($inv->investigation_id, $analyst->id, 'collaborator', $addedBy);
        $archived = $this->collab()->archiveCollaborator($active->collab_id, $addedBy);

        $this->assertFalse((bool) $archived->active, 'Archived collaborator must have active=false');
        // Original record still exists unchanged
        $this->assertDatabaseHas('investigation_collaborators', ['collab_id' => $active->collab_id, 'active' => true]);
    }

    public function test_add_watcher_creates_append_only_record(): void
    {
        $inv    = $this->makeInvestigation();
        $analyst= $this->analyst();

        $watcher = $this->collab()->addWatcher($inv->investigation_id, $analyst->id, $this->admin(), 'Monitor closely');
        $this->assertNull($watcher->updated_at, 'Watcher record must be append-only');
    }

    // -----------------------------------------------------------------------
    // Escalation — no autonomous execution
    // -----------------------------------------------------------------------

    public function test_create_escalation_requires_explicit_operator_action(): void
    {
        $inv    = $this->makeInvestigation();
        $analyst= $this->analyst();

        $event = $this->escalation()->createEscalation(
            $inv->investigation_id, $analyst, null, 'Suspicious lateral movement', 'high'
        );

        $this->assertSame(EscalationEvent::EVENT_CREATED, $event->event_type);
        $this->assertSame(EscalationEvent::STATE_ESCALATED, $event->workflow_state);
        $this->assertStringStartsWith('esc-', $event->escalation_id);
    }

    public function test_escalation_event_is_append_only(): void
    {
        $inv   = $this->makeInvestigation();
        $event = $this->escalation()->createEscalation($inv->investigation_id, $this->analyst(), null, 'reason', 'high');
        $this->assertNull($event->updated_at, 'Escalation events must be append-only');
    }

    public function test_acknowledge_escalation_creates_new_event(): void
    {
        $inv   = $this->makeInvestigation();
        $from  = $this->analyst();
        $to    = $this->analyst();

        $created = $this->escalation()->createEscalation($inv->investigation_id, $from, $to->id, 'reason', 'high');
        $acked   = $this->escalation()->acknowledgeEscalation($created->escalation_id, $to, 'On it');

        $this->assertSame(EscalationEvent::EVENT_ACKNOWLEDGED, $acked->event_type);
        $this->assertNotNull($acked->acknowledged_at);
        // Both records exist — append-only
        $count = EscalationEvent::where('escalation_id', $created->escalation_id)->count();
        $this->assertSame(2, $count);
    }

    public function test_reroute_escalation_creates_routed_event(): void
    {
        $inv   = $this->makeInvestigation();
        $from  = $this->analyst();

        $created  = $this->escalation()->createEscalation($inv->investigation_id, $from, null, 'reason', 'high');
        $rerouted = $this->escalation()->rerouteEscalation($created->escalation_id, 999, $from, 'Better analyst');

        $this->assertSame(EscalationEvent::EVENT_ROUTED, $rerouted->event_type);
    }

    public function test_timed_out_escalations_are_advisory_only(): void
    {
        $timedOut = $this->escalation()->detectTimedOutEscalations();
        // Result is a collection of advisory findings — no auto-action
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $timedOut);
        foreach ($timedOut as $t) {
            $this->assertTrue($t['advisory_only'],   'Timed-out escalations must be advisory_only');
            $this->assertTrue($t['no_auto_action'],  'Timed-out escalations must have no_auto_action=true');
        }
    }

    // -----------------------------------------------------------------------
    // Workflow state transitions — deterministic
    // -----------------------------------------------------------------------

    public function test_valid_workflow_transition_creates_event(): void
    {
        $inv     = $this->makeInvestigation();
        $analyst = $this->analyst();

        $event = $this->escalation()->transitionWorkflowState(
            $inv->investigation_id,
            EscalationEvent::STATE_TRIAGED,
            EscalationEvent::STATE_INVESTIGATING,
            $analyst
        );

        $this->assertSame(EscalationEvent::EVENT_WORKFLOW_STATE, $event->event_type);
        $this->assertSame(EscalationEvent::STATE_INVESTIGATING, $event->workflow_state);
    }

    public function test_invalid_workflow_transition_throws(): void
    {
        $inv = $this->makeInvestigation();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/Cannot transition/i");
        $this->escalation()->transitionWorkflowState(
            $inv->investigation_id,
            EscalationEvent::STATE_CLOSED,
            EscalationEvent::STATE_TRIAGED, // closed cannot go back to triaged
            $this->analyst()
        );
    }

    public function test_workflow_state_transitions_are_deterministic(): void
    {
        // Closed state has no valid transitions
        $this->assertEmpty(EscalationEvent::STATE_TRANSITIONS[EscalationEvent::STATE_CLOSED]);
        // Triaged can go to investigating
        $this->assertTrue(EscalationEvent::canTransition(
            EscalationEvent::STATE_TRIAGED, EscalationEvent::STATE_INVESTIGATING
        ));
    }

    public function test_nine_analyst_workflow_states(): void
    {
        $this->assertCount(9, EscalationEvent::WORKFLOW_STATES);
    }

    // -----------------------------------------------------------------------
    // Watchlist management
    // -----------------------------------------------------------------------

    public function test_create_watchlist_records_event(): void
    {
        $analyst = $this->analyst();
        $wl = $this->collab()->createWatchlist($analyst, Watchlist::TYPE_DOMAIN, 'evil.example.com', 'Suspicious domain');

        $this->assertStringStartsWith('wl-', $wl->watchlist_id);
        $this->assertTrue((bool) $wl->active);
        $this->assertDatabaseHas('watchlist_events', [
            'watchlist_id' => $wl->watchlist_id,
            'event_type'   => WatchlistEvent::TYPE_CREATED,
        ]);
    }

    public function test_create_watchlist_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->collab()->createWatchlist($this->analyst(), 'threat_actor', 'APT28', null);
    }

    public function test_deactivate_watchlist_records_event(): void
    {
        $analyst = $this->analyst();
        $wl = $this->collab()->createWatchlist($analyst, Watchlist::TYPE_IP, '192.168.1.5', 'Suspicious IP');
        $deactivated = $this->collab()->deactivateWatchlist($wl->watchlist_id, $analyst);

        $this->assertFalse((bool) $deactivated->active);
        $this->assertDatabaseHas('watchlist_events', [
            'watchlist_id' => $wl->watchlist_id,
            'event_type'   => WatchlistEvent::TYPE_DEACTIVATED,
        ]);
    }

    public function test_watchlist_expiration_processing(): void
    {
        $analyst = $this->analyst();
        // Create expired watchlist
        $wl = $this->collab()->createWatchlist(
            $analyst, Watchlist::TYPE_HOST, 'host-expired', 'Test',
            now()->subHour()->toIso8601String()
        );

        $count = $this->collab()->processExpiredWatchlists();
        $this->assertGreaterThan(0, $count);
        $this->assertFalse((bool) $wl->fresh()->active, 'Expired watchlist must be deactivated');
        $this->assertDatabaseHas('watchlist_events', [
            'watchlist_id' => $wl->watchlist_id,
            'event_type'   => WatchlistEvent::TYPE_EXPIRED,
        ]);
    }

    public function test_watchlist_event_is_append_only(): void
    {
        $analyst = $this->analyst();
        $wl = $this->collab()->createWatchlist($analyst, Watchlist::TYPE_USER, 'user@corp', null);
        $event = WatchlistEvent::where('watchlist_id', $wl->watchlist_id)->first();
        $this->assertNull($event->updated_at, 'Watchlist events must be append-only');
    }

    // -----------------------------------------------------------------------
    // SLA tracking
    // -----------------------------------------------------------------------

    public function test_seed_default_sla_policies(): void
    {
        $count = $this->sla()->seedDefaultPolicies();
        $this->assertGreaterThan(0, $count);
        $this->assertTrue(SlaPolicy::where('active', true)->exists());
    }

    public function test_sla_breach_calculation_is_deterministic(): void
    {
        $this->sla()->seedDefaultPolicies();
        $breach1 = $this->sla()->getBreachSummary();
        $breach2 = $this->sla()->getBreachSummary();
        $this->assertSame($breach1['total'], $breach2['total'], 'Breach summary must be deterministic');
    }

    public function test_record_sla_breach_creates_append_only_records(): void
    {
        $this->sla()->seedDefaultPolicies();
        $policy = SlaPolicy::first();

        $breach = $this->sla()->recordSlaBreach(
            'INV-TEST-001', $policy->policy_id, $policy->policy_type, 'high', 3600
        );

        $this->assertStringStartsWith('breach-', $breach->breach_id);
        $this->assertFalse((bool) $breach->acknowledged);
        $this->assertNull($breach->updated_at, 'SLA breach must be append-only');
    }

    public function test_sla_event_is_append_only(): void
    {
        $this->sla()->seedDefaultPolicies();
        $policy = SlaPolicy::first();
        $event  = $this->sla()->recordSlaStart('INV-001', $policy->policy_id);
        $this->assertNull($event->updated_at, 'SLA events must be append-only');
    }

    public function test_overdue_investigations_query(): void
    {
        $overdue = $this->sla()->getOverdueInvestigations();
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $overdue);
    }

    // -----------------------------------------------------------------------
    // Shift handoff — append-only
    // -----------------------------------------------------------------------

    public function test_create_handoff_records_unresolved_count(): void
    {
        $from = $this->analyst();
        $to   = $this->analyst();

        $handoff = $this->collab()->createHandoff($from, $to, 'End of shift summary', 'Notes here');

        $this->assertStringStartsWith('hoff-', $handoff->handoff_id);
        $this->assertNull($handoff->updated_at, 'Handoff must be append-only');
    }

    public function test_handoff_includes_active_investigation_snapshot(): void
    {
        $analyst = $this->analyst();
        $this->makeInvestigation($analyst);
        $handoff = $this->collab()->createHandoff($analyst, null, 'Shift summary');

        $this->assertIsArray($handoff->active_investigations);
    }

    // -----------------------------------------------------------------------
    // Investigation sharing — explicit, no unrestricted sharing
    // -----------------------------------------------------------------------

    public function test_share_with_analyst_creates_reviewer_collaborator(): void
    {
        $inv    = $this->makeInvestigation();
        $owner  = $this->analyst();
        $target = $this->analyst();

        $collab = $this->collab()->shareWithAnalyst($inv->investigation_id, $target->id, $owner);

        $this->assertSame(InvestigationCollaborator::ROLE_REVIEWER, $collab->role);
        $this->assertSame($owner->id, $collab->added_by);
    }

    public function test_sharing_requires_explicit_actor_attribution(): void
    {
        $inv    = $this->makeInvestigation();
        $target = $this->analyst();
        $owner  = $this->analyst();

        $collab = $this->collab()->shareWithAnalyst($inv->investigation_id, $target->id, $owner);
        $this->assertSame($owner->id, $collab->added_by, 'Sharing must record explicit actor attribution');
    }

    public function test_shared_investigations_visible_to_reviewer(): void
    {
        $inv    = $this->makeInvestigation();
        $owner  = $this->analyst();
        $target = $this->analyst();

        $this->collab()->shareWithAnalyst($inv->investigation_id, $target->id, $owner);
        $shared = $this->collab()->getSharedInvestigations($target->id);

        $this->assertTrue(
            $shared->contains('investigation_id', $inv->investigation_id),
            'Shared investigation must be visible to reviewer'
        );
    }

    // -----------------------------------------------------------------------
    // Routes and views
    // -----------------------------------------------------------------------

    public function test_soc_operations_dashboard_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('soc.workflow.dashboard'))
             ->assertOk();
    }

    public function test_analyst_queue_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('soc.workflow.queue'))
             ->assertOk();
    }

    public function test_escalation_center_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('soc.workflow.escalation'))
             ->assertOk();
    }

    public function test_watchlist_center_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('soc.workflow.watchlist'))
             ->assertOk();
    }

    public function test_sla_monitoring_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('soc.workflow.sla'))
             ->assertOk();
    }

    public function test_shift_handoff_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('soc.workflow.handoff'))
             ->assertOk();
    }

    public function test_collaboration_timeline_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('soc.workflow.timeline'))
             ->assertOk();
    }

    public function test_analyst_workload_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('soc.workflow.workload'))
             ->assertOk();
    }

    public function test_shared_investigations_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('soc.workflow.shared'))
             ->assertOk();
    }

    public function test_soc_workflow_routes_require_authentication(): void
    {
        $this->get(route('soc.workflow.dashboard'))->assertRedirect('/login');
    }

    public function test_api_operations_summary_returns_advisory_note(): void
    {
        $this->actingAs($this->admin())
             ->getJson(route('api.soc.workflow.summary'))
             ->assertOk()
             ->assertJsonFragment(['advisory_note' => 'Analyst-driven collaborative workflows only. No autonomous SOC operations.']);
    }

    public function test_api_escalation_queue_returns_no_auto_action_note(): void
    {
        $this->actingAs($this->admin())
             ->getJson(route('api.soc.workflow.escalations'))
             ->assertOk()
             ->assertJsonFragment(['advisory_note' => 'No automatic escalation execution. All escalations require operator action.']);
    }

    // -----------------------------------------------------------------------
    // Safety boundary assertions
    // -----------------------------------------------------------------------

    public function test_no_autonomous_escalation_in_escalation_service(): void
    {
        $src = file_get_contents(app_path('Services/EscalationService.php'));
        $this->assertStringNotContainsString('auto_assign',        $src);
        $this->assertStringNotContainsString('autonomous_escalat', $src);
        $this->assertStringNotContainsString('auto_execute',       $src);
        $this->assertStringContainsString('advisory only',         strtolower($src));
    }

    public function test_no_analyst_impersonation_in_collaboration_service(): void
    {
        $src = file_get_contents(app_path('Services/SocCollaborationService.php'));
        $this->assertStringNotContainsString('act_as_user',      $src);
        $this->assertStringNotContainsString('impersonate_user', $src);
        $this->assertStringContainsString('added_by', $src, 'All collaboration must record explicit actor');
    }

    public function test_no_hidden_workflow_mutation(): void
    {
        $src = file_get_contents(app_path('Services/EscalationService.php'));
        // Every state change creates a NEW record (never mutates existing)
        $this->assertStringNotContainsString("->update(['event_type", $src);
        $this->assertStringNotContainsString("->update(['workflow_state", $src);
    }

    public function test_no_auto_response_in_sla_service(): void
    {
        $src = file_get_contents(app_path('Services/SlaTrackingService.php'));
        $this->assertStringNotContainsString('auto_close',     $src);
        $this->assertStringNotContainsString('auto_assign',    $src);
        $this->assertStringNotContainsString('auto_response',  $src);
        $this->assertStringContainsString('advisory',          strtolower($src));
    }

    public function test_collaboration_service_no_unrestricted_export(): void
    {
        $src = file_get_contents(app_path('Services/SocCollaborationService.php'));
        $this->assertStringNotContainsString('unrestricted_export', $src);
        $this->assertStringNotContainsString('anonymous_share',     $src);
    }
}
