<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TraceInvestigationTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): \App\Models\User
    {
        return \App\Models\User::factory()->create(['role' => 'admin']);
    }

    private function viewerUser(): \App\Models\User
    {
        return \App\Models\User::factory()->create(['role' => 'viewer']);
    }

    private function seedAlert(string $traceId, array $overrides = []): string
    {
        $alertId = 'xdr-alert-'.substr($traceId, 0, 8);
        DB::table('security_alerts')->insert(array_merge([
            'alert_id' => $alertId,
            'alert_fingerprint' => md5($alertId),
            'alert_type' => 'IDENTITY_MFA_FAILURE_BURST',
            'severity' => 'high',
            'detected_at' => now()->toIsoString(),
            'actor_key' => 'scenario-actor@test.local',
            'ip' => '10.0.0.1',
            'score' => 0.85,
            'detector_name' => 'xdr-correlation',
            'detector_version' => 'go-shadow',
            'evidence' => '{}',
            'raw_event' => '{}',
            'trace_id' => $traceId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $alertId;
    }

    private function seedIncident(string $traceId, string $alertId): string
    {
        $incidentId = 'xdr-inc-'.substr($traceId, 0, 16);
        DB::table('security_incidents')->insert([
            'incident_id' => $incidentId,
            'title' => 'XDR grouped incident',
            'status' => 'open',
            'severity' => 'high',
            'confidence' => 0.85,
            'first_seen_at' => now()->toIsoString(),
            'last_seen_at' => now()->toIsoString(),
            'affected_entities' => '[]',
            'timeline' => '[]',
            'mitre_mapping' => '[]',
            'metadata' => '{}',
            'xdr_domains' => '[]',
            'trace_id' => $traceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('security_incident_alerts')->insert([
            'incident_id' => $incidentId,
            'alert_id' => $alertId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $incidentId;
    }

    private function seedOperationalEvent(string $traceId, string $eventType = 'alert.created'): void
    {
        DB::table('xdr_operational_events')->insert([
            'event_id' => 'evt-'.uniqid(),
            'event_type' => $eventType,
            'schema_version' => 1,
            'source_topic' => 'alerts.created',
            'source_service' => 'alert-writer-service',
            'trace_id' => $traceId,
            'occurred_at' => now()->toIsoString(),
            'payload' => '{}',
            'metadata' => '{}',
            'replayable' => true,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Access control
    // -----------------------------------------------------------------------

    public function test_trace_search_requires_authentication(): void
    {
        $this->get(route('traces.index'))->assertRedirect(route('login'));
    }

    public function test_viewer_can_access_trace_search(): void
    {
        $this->actingAs($this->viewerUser())
            ->get(route('traces.index'))
            ->assertOk()
            ->assertSee('Trace Investigation');
    }

    public function test_admin_can_access_trace_search(): void
    {
        $this->actingAs($this->adminUser())
            ->get(route('traces.index'))
            ->assertOk();
    }

    // -----------------------------------------------------------------------
    // Trace search
    // -----------------------------------------------------------------------

    public function test_search_by_trace_id_returns_matching_row(): void
    {
        $traceId = 'test-trace-'.uniqid();
        $this->seedAlert($traceId);

        $this->actingAs($this->adminUser())
            ->get(route('traces.index', ['q' => $traceId, 'by' => 'trace_id']))
            ->assertOk()
            ->assertSee($traceId);
    }

    public function test_search_by_alert_id_returns_linked_trace(): void
    {
        $traceId = 'test-trace-'.uniqid();
        $alertId = $this->seedAlert($traceId);

        $this->actingAs($this->adminUser())
            ->get(route('traces.index', ['q' => $alertId, 'by' => 'alert_id']))
            ->assertOk()
            ->assertSee($traceId);
    }

    public function test_search_by_actor_key_returns_linked_trace(): void
    {
        $traceId = 'test-trace-'.uniqid();
        $this->seedAlert($traceId, ['actor_key' => 'bob@example.com']);

        $this->actingAs($this->adminUser())
            ->get(route('traces.index', ['q' => 'bob@example.com', 'by' => 'actor_key']))
            ->assertOk()
            ->assertSee($traceId);
    }

    public function test_search_by_ip_returns_linked_trace(): void
    {
        $traceId = 'test-trace-'.uniqid();
        $this->seedAlert($traceId, ['ip' => '192.168.1.50']);

        $this->actingAs($this->adminUser())
            ->get(route('traces.index', ['q' => '192.168.1.50', 'by' => 'ip']))
            ->assertOk()
            ->assertSee($traceId);
    }

    public function test_search_with_no_match_shows_empty_state(): void
    {
        $this->actingAs($this->adminUser())
            ->get(route('traces.index', ['q' => 'nonexistent-trace-id-xyz', 'by' => 'trace_id']))
            ->assertOk()
            ->assertSee('No traces found');
    }

    // -----------------------------------------------------------------------
    // Trace show / timeline
    // -----------------------------------------------------------------------

    public function test_trace_show_displays_timeline_and_alerts(): void
    {
        $traceId = 'test-trace-'.uniqid();
        $alertId = $this->seedAlert($traceId);
        $this->seedIncident($traceId, $alertId);
        $this->seedOperationalEvent($traceId);

        $this->actingAs($this->adminUser())
            ->get(route('traces.show', $traceId))
            ->assertOk()
            ->assertSee($traceId)
            ->assertSee('Event Timeline')
            ->assertSee('Correlated Alerts')
            ->assertSee('Linked Incidents');
    }

    public function test_trace_show_summary_counts_are_correct(): void
    {
        $traceId = 'test-trace-'.uniqid();
        $alertId1 = $this->seedAlert($traceId, ['alert_id' => 'a1-'.uniqid(), 'alert_fingerprint' => uniqid()]);
        $alertId2 = $this->seedAlert($traceId, ['alert_id' => 'a2-'.uniqid(), 'alert_fingerprint' => uniqid(), 'severity' => 'critical']);
        $this->seedIncident($traceId, $alertId1);
        $this->seedOperationalEvent($traceId, 'alert.created');
        $this->seedOperationalEvent($traceId, 'incident.updated');

        $response = $this->actingAs($this->adminUser())
            ->get(route('traces.show', $traceId))
            ->assertOk();

        $response->assertSee('2');
        $response->assertSee('1');
    }

    public function test_trace_show_pipeline_flow_marks_active_services(): void
    {
        $traceId = 'test-trace-'.uniqid();
        $alertId = $this->seedAlert($traceId);
        $this->seedIncident($traceId, $alertId);

        $this->actingAs($this->adminUser())
            ->get(route('traces.show', $traceId))
            ->assertOk()
            ->assertSee('Alert Writer')
            ->assertSee('Incident Builder');
    }

    // -----------------------------------------------------------------------
    // API endpoints
    // -----------------------------------------------------------------------

    public function test_api_traces_index_returns_json(): void
    {
        $traceId = 'test-trace-'.uniqid();
        $this->seedAlert($traceId);

        $response = $this->actingAs($this->adminUser())
            ->getJson(route('api.traces.index'))
            ->assertOk()
            ->assertJsonStructure(['traces', 'total']);

        $this->assertIsArray($response->json('traces'));
    }

    public function test_api_traces_search_by_trace_id(): void
    {
        $traceId = 'test-trace-'.uniqid();
        $this->seedAlert($traceId);

        $this->actingAs($this->adminUser())
            ->getJson(route('api.traces.index', ['q' => $traceId, 'by' => 'trace_id']))
            ->assertOk()
            ->assertJsonPath('traces.0.trace_id', $traceId)
            ->assertJsonPath('traces.0.alerts_count', 1);
    }

    public function test_api_trace_show_returns_full_detail(): void
    {
        $traceId = 'test-trace-'.uniqid();
        $alertId = $this->seedAlert($traceId);
        $this->seedIncident($traceId, $alertId);

        $this->actingAs($this->adminUser())
            ->getJson(route('api.traces.show', $traceId))
            ->assertOk()
            ->assertJsonPath('trace_id', $traceId)
            ->assertJsonPath('alerts_count', 1)
            ->assertJsonPath('incidents_count', 1);
    }

    public function test_api_trace_show_returns_404_for_unknown_trace(): void
    {
        $this->actingAs($this->adminUser())
            ->getJson(route('api.traces.show', 'nonexistent-trace-xyz-999'))
            ->assertNotFound()
            ->assertJsonPath('error', 'trace_not_found');
    }

    public function test_api_trace_timeline_returns_ordered_events(): void
    {
        $traceId = 'test-trace-'.uniqid();
        $this->seedAlert($traceId);
        $this->seedOperationalEvent($traceId);

        $this->actingAs($this->adminUser())
            ->getJson(route('api.traces.timeline', $traceId))
            ->assertOk()
            ->assertJsonStructure(['trace_id', 'count', 'timeline'])
            ->assertJsonPath('trace_id', $traceId);
    }

    public function test_api_trace_alerts_returns_alert_list(): void
    {
        $traceId = 'test-trace-'.uniqid();
        $this->seedAlert($traceId);

        $this->actingAs($this->adminUser())
            ->getJson(route('api.traces.alerts', $traceId))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('trace_id', $traceId);
    }

    public function test_api_trace_incidents_returns_incident_list(): void
    {
        $traceId = 'test-trace-'.uniqid();
        $alertId = $this->seedAlert($traceId);
        $this->seedIncident($traceId, $alertId);

        $this->actingAs($this->adminUser())
            ->getJson(route('api.traces.incidents', $traceId))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('trace_id', $traceId);
    }

    public function test_api_trace_evidence_returns_scenario_evidence(): void
    {
        $traceId = 'test-trace-'.uniqid();
        $user = $this->adminUser();

        DB::table('scenario_runs')->insert([
            'scenario_id' => 'failed_login_burst',
            'user_id' => $user->id,
            'status' => 'completed',
            'run_mode' => 'stub',
            'trace_id' => $traceId,
            'config' => '{}',
            'results' => '{}',
            'alerts_detected' => 0,
            'detection_passed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $runId = DB::getPdo()->lastInsertId();

        DB::table('scenario_evidence')->insert([
            'scenario_run_id' => $runId,
            'stage' => 'simulated_stage',
            'stage_type' => 'simulated_runner_stage',
            'event_id' => 'ev-'.uniqid(),
            'trace_id' => $traceId,
            'status' => 'detected',
            'payload' => '{}',
            'processed_at' => now()->toIsoString(),
            'latency_ms' => 25,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->adminUser())
            ->getJson(route('api.traces.evidence', $traceId))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('trace_id', $traceId);
    }

    // -----------------------------------------------------------------------
    // trace_id integrity across pipeline tables
    // -----------------------------------------------------------------------

    public function test_same_trace_id_links_alert_and_incident(): void
    {
        $traceId = 'integrity-test-'.uniqid();
        $alertId = $this->seedAlert($traceId);
        $incidentId = $this->seedIncident($traceId, $alertId);

        $alertRow = DB::table('security_alerts')->where('alert_id', $alertId)->first();
        $incidentRow = DB::table('security_incidents')->where('incident_id', $incidentId)->first();

        $this->assertSame($traceId, $alertRow->trace_id);
        $this->assertSame($traceId, $incidentRow->trace_id);
        $this->assertSame($alertRow->trace_id, $incidentRow->trace_id, 'Alert and incident must share the same trace_id');
    }

    public function test_operational_event_trace_id_matches_alert_trace_id(): void
    {
        $traceId = 'ops-trace-'.uniqid();
        $this->seedAlert($traceId);
        $this->seedOperationalEvent($traceId, 'alert.created');

        $opEvent = DB::table('xdr_operational_events')->where('trace_id', $traceId)->first();
        $alert = DB::table('security_alerts')->where('trace_id', $traceId)->first();

        $this->assertNotNull($opEvent);
        $this->assertSame($traceId, $opEvent->trace_id);
        $this->assertSame($opEvent->trace_id, $alert->trace_id, 'Operational event and alert must share trace_id');
    }

    public function test_trace_search_counts_across_all_linked_tables(): void
    {
        $traceId = 'count-test-'.uniqid();
        $alertId = $this->seedAlert($traceId);
        $this->seedIncident($traceId, $alertId);
        $this->seedOperationalEvent($traceId);

        $response = $this->actingAs($this->adminUser())
            ->getJson(route('api.traces.show', $traceId))
            ->assertOk();

        $this->assertSame(1, $response->json('alerts_count'));
        $this->assertSame(1, $response->json('incidents_count'));
        $this->assertSame(1, $response->json('events_count'));
    }

    public function test_trace_id_column_exists_on_security_incidents(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('security_incidents', 'trace_id')
        );
    }

    public function test_grafana_dashboard_json_is_valid(): void
    {
        $path = base_path('docs/grafana/trace-flow-dashboard.json');
        $this->assertFileExists($path);
        $dashboard = json_decode(file_get_contents($path), true);
        $this->assertIsArray($dashboard);
        $this->assertSame('XDR Trace Flow Dashboard', $dashboard['title']);
        $this->assertNotEmpty($dashboard['panels']);
    }
}
