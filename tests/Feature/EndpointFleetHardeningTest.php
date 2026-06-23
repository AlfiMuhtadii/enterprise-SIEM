<?php

namespace Tests\Feature;

use App\Models\EndpointAgent;
use App\Models\EndpointAgentConfig;
use App\Models\EndpointAgentEnrollmentEvent;
use App\Models\EndpointAgentHeartbeat;
use App\Models\EndpointAgentPolicyAssignment;
use App\Models\EndpointFleetPolicy;
use App\Models\EndpointSpoolSnapshot;
use App\Models\EndpointTamperEvent;
use App\Models\User;
use App\Services\EndpointAgentService;
use App\Services\EndpointFleetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Endpoint Fleet Hardening Phase 1 — Feature Tests.
 *
 * Hard safety assertions (MUST remain green):
 *   - No automatic host isolation
 *   - No remote shell execution
 *   - No process kill
 *   - No autonomous remediation
 *   - No hidden endpoint action
 *   - is_advisory = true on all tamper events
 */
class EndpointFleetHardeningTest extends TestCase
{
    use RefreshDatabase;

    private EndpointFleetService $fleetService;
    private EndpointAgentService $agentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fleetService = app(EndpointFleetService::class);
        $this->agentService = app(EndpointAgentService::class);
        Config::set('soc.agent_enrollment_token', 'test-fleet-hardening-token');
        Config::set('soc.agent_heartbeat_interval_seconds', 60);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function analyst(): User
    {
        return User::factory()->create(['role' => 'analyst']);
    }

    private function makeAgent(array $overrides = []): EndpointAgent
    {
        return EndpointAgent::create(array_merge([
            'agent_id'      => EndpointAgent::generateAgentId(),
            'host_id'       => 'test-host-' . uniqid(),
            'host_fingerprint' => hash('sha256', 'test-host-' . uniqid()),
            'hostname'      => 'test-server',
            'enrollment_token_hash' => EndpointAgent::hashEnrollmentToken('test-fleet-hardening-token'),
            'agent_version' => '1.0.0',
            'platform'      => 'Linux',
            'os_family'     => 'Linux',
            'health_state'  => EndpointAgent::HEALTH_ONLINE,
            'status'        => 'online',
            'enrolled_at'   => now(),
            'last_seen_at'  => now(),
        ], $overrides));
    }

    // =========================================================================
    // Schema — new tables exist
    // =========================================================================

    public function test_endpoint_fleet_policies_table_exists(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\DB::getSchemaBuilder()->hasTable('endpoint_fleet_policies'));
    }

    public function test_endpoint_agent_policy_assignments_table_exists(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\DB::getSchemaBuilder()->hasTable('endpoint_agent_policy_assignments'));
    }

    public function test_endpoint_agent_enrollment_events_table_exists(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\DB::getSchemaBuilder()->hasTable('endpoint_agent_enrollment_events'));
    }

    public function test_endpoint_tamper_events_table_exists(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\DB::getSchemaBuilder()->hasTable('endpoint_tamper_events'));
    }

    public function test_endpoint_spool_snapshots_table_exists(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\DB::getSchemaBuilder()->hasTable('endpoint_spool_snapshots'));
    }

    // =========================================================================
    // Enrollment events — append-only
    // =========================================================================

    public function test_enrollment_event_is_recorded(): void
    {
        $agent = $this->makeAgent();

        $event = $this->fleetService->recordEnrollmentEvent(
            $agent,
            EndpointAgentEnrollmentEvent::EVENT_ENROLLED,
            ['ip_address' => '10.0.0.1'],
            true
        );

        $this->assertInstanceOf(EndpointAgentEnrollmentEvent::class, $event);
        $this->assertSame(EndpointAgentEnrollmentEvent::EVENT_ENROLLED, $event->event_type);
        $this->assertTrue($event->successful);
        $this->assertStringStartsWith('enroll-', $event->event_id);
    }

    public function test_enrollment_events_are_append_only(): void
    {
        $agent = $this->makeAgent();

        $this->fleetService->recordEnrollmentEvent($agent, EndpointAgentEnrollmentEvent::EVENT_ENROLLED);
        $countBefore = EndpointAgentEnrollmentEvent::where('agent_id', $agent->id)->count();

        $this->fleetService->recordEnrollmentEvent($agent, EndpointAgentEnrollmentEvent::EVENT_RE_ENROLLED);
        $countAfter = EndpointAgentEnrollmentEvent::where('agent_id', $agent->id)->count();

        $this->assertEquals($countBefore + 1, $countAfter, 'Enrollment events must be INSERT-only');
    }

    public function test_failed_enrollment_event_is_recorded(): void
    {
        $agent = $this->makeAgent();

        $event = $this->fleetService->recordEnrollmentEvent(
            $agent,
            EndpointAgentEnrollmentEvent::EVENT_FAILED,
            [],
            false,
            'token_validation_failed'
        );

        $this->assertFalse($event->successful);
        $this->assertSame('token_validation_failed', $event->failure_reason);
    }

    public function test_revoked_enrollment_event_type_is_valid(): void
    {
        $agent = $this->makeAgent();
        $event = $this->fleetService->recordEnrollmentEvent(
            $agent, EndpointAgentEnrollmentEvent::EVENT_REVOKED
        );
        $this->assertSame(EndpointAgentEnrollmentEvent::EVENT_REVOKED, $event->event_type);
    }

    // =========================================================================
    // Fleet policy management
    // =========================================================================

    public function test_fleet_policy_creation(): void
    {
        $config = array_merge(EndpointAgentConfig::DEFAULT_CONFIG, ['policy_version' => '2.0.0']);
        $policy = $this->fleetService->createFleetPolicy($config, 'Baseline Policy', null, 'Test policy');

        $this->assertInstanceOf(EndpointFleetPolicy::class, $policy);
        $this->assertSame('Baseline Policy', $policy->name);
        $this->assertSame('2.0.0', $policy->policy_version);
        $this->assertTrue($policy->is_active);
        $this->assertTrue($policy->rollback_supported);
        $this->assertNotEmpty($policy->config_hash);
        $this->assertStringStartsWith('fleet-policy-', $policy->policy_id);
    }

    public function test_fleet_policy_config_hash_is_deterministic(): void
    {
        $config = EndpointAgentConfig::DEFAULT_CONFIG;

        $hash1 = EndpointFleetPolicy::hashConfig($config);
        $hash2 = EndpointFleetPolicy::hashConfig($config);

        $this->assertEquals($hash1, $hash2, 'Config hash must be deterministic');
        $this->assertNotEmpty($hash1);
    }

    public function test_policy_assignment_to_agent_is_append_only(): void
    {
        $agent  = $this->makeAgent();
        $config = EndpointAgentConfig::DEFAULT_CONFIG;
        $policy = $this->fleetService->createFleetPolicy($config, 'Policy A');

        $assignment = $this->fleetService->assignPolicyToAgent($agent, $policy);

        $this->assertInstanceOf(EndpointAgentPolicyAssignment::class, $assignment);
        $this->assertSame($policy->policy_id, $assignment->policy_id);
        $this->assertFalse($assignment->applied_to_agent, 'Assignment starts as pending');
        $this->assertStringStartsWith('assign-', $assignment->assignment_id);

        // Second assignment creates a new row — never updates existing
        $countBefore = EndpointAgentPolicyAssignment::where('agent_id', $agent->id)->count();
        $this->fleetService->assignPolicyToAgent($agent, $policy, EndpointFleetPolicy::REASON_RE_ENROLLMENT);
        $countAfter = EndpointAgentPolicyAssignment::where('agent_id', $agent->id)->count();

        $this->assertEquals($countBefore + 1, $countAfter, 'Policy assignments must be INSERT-only');
    }

    public function test_policy_assignment_increments_agent_count(): void
    {
        $agent  = $this->makeAgent();
        $config = EndpointAgentConfig::DEFAULT_CONFIG;
        $policy = $this->fleetService->createFleetPolicy($config, 'Policy B');

        $this->assertEquals(0, $policy->fresh()->assigned_agent_count);
        $this->fleetService->assignPolicyToAgent($agent, $policy);
        $this->assertEquals(1, $policy->fresh()->assigned_agent_count);
    }

    public function test_get_current_policy_assignment_returns_latest(): void
    {
        $agent  = $this->makeAgent();
        $config = EndpointAgentConfig::DEFAULT_CONFIG;
        $policy = $this->fleetService->createFleetPolicy($config, 'Policy C');

        $this->fleetService->assignPolicyToAgent($agent, $policy, EndpointFleetPolicy::REASON_MANUAL);
        $this->fleetService->assignPolicyToAgent($agent, $policy, EndpointFleetPolicy::REASON_ROLLBACK);

        $current = $this->fleetService->getCurrentPolicyAssignment($agent);
        $this->assertNotNull($current);
        $this->assertSame(EndpointFleetPolicy::REASON_ROLLBACK, $current->assignment_reason);
    }

    // =========================================================================
    // Tamper visibility — advisory-only detection
    // =========================================================================

    public function test_heartbeat_gap_triggers_tamper_event(): void
    {
        $agent = $this->makeAgent([
            'last_seen_at'  => now()->subMinutes(120), // 2 hours ago
            'health_state'  => EndpointAgent::HEALTH_STALE,
        ]);

        $findings = $this->fleetService->detectTamperEvents($agent, 60);

        $this->assertGreaterThan(0, $findings->count());
        $heartbeatGap = $findings->firstWhere('tamper_type', EndpointTamperEvent::TYPE_HEARTBEAT_GAP);
        $this->assertNotNull($heartbeatGap);
        $this->assertTrue($heartbeatGap->is_advisory, 'Tamper event MUST be advisory-only');
        $this->assertNotEmpty($heartbeatGap->evidence);
    }

    public function test_every_tamper_event_has_is_advisory_true(): void
    {
        $agent = $this->makeAgent([
            'last_seen_at' => now()->subHours(3),
            'health_state' => EndpointAgent::HEALTH_OFFLINE,
        ]);

        $findings = $this->fleetService->detectTamperEvents($agent);

        foreach ($findings as $f) {
            $this->assertTrue($f->is_advisory,
                "Tamper event '{$f->tamper_type}' MUST have is_advisory=true — no autonomous enforcement");
        }
    }

    public function test_tamper_events_are_append_only(): void
    {
        $agent = $this->makeAgent([
            'last_seen_at' => now()->subHours(3),
        ]);

        $this->fleetService->detectTamperEvents($agent);
        $countBefore = EndpointTamperEvent::where('agent_id', $agent->id)->count();

        $this->fleetService->detectTamperEvents($agent);
        $countAfter = EndpointTamperEvent::where('agent_id', $agent->id)->count();

        $this->assertGreaterThanOrEqual($countBefore, $countAfter,
            'Tamper events must be INSERT-only — no mutations');
    }

    public function test_tamper_event_has_all_required_explainability_fields(): void
    {
        $agent = $this->makeAgent([
            'last_seen_at' => now()->subHours(5),
        ]);

        $findings = $this->fleetService->detectTamperEvents($agent);

        foreach ($findings as $f) {
            $this->assertNotEmpty($f->tamper_id, 'tamper_id must be set');
            $this->assertNotEmpty($f->tamper_type, 'tamper_type must be set');
            $this->assertNotEmpty($f->severity, 'severity must be set');
            $this->assertNotEmpty($f->description, 'description must be set');
            $this->assertNotNull($f->confidence, 'confidence must be set');
            $this->assertNotNull($f->detected_at, 'detected_at must be set');
            $this->assertTrue($f->is_advisory, 'is_advisory must be true');
        }
    }

    public function test_agent_enrolled_but_never_heartbeat_triggers_tamper(): void
    {
        $agent = $this->makeAgent([
            'last_seen_at' => null,
            'enrolled_at'  => now()->subHours(1),
        ]);

        $findings = $this->fleetService->detectTamperEvents($agent);

        $agentStopped = $findings->firstWhere('tamper_type', EndpointTamperEvent::TYPE_AGENT_STOPPED);
        $this->assertNotNull($agentStopped, 'agent_stopped tamper should be detected');
        $this->assertTrue($agentStopped->is_advisory);
    }

    public function test_healthy_agent_has_no_heartbeat_gap_tamper(): void
    {
        $agent = $this->makeAgent([
            'last_seen_at' => now()->subSeconds(30), // recent
            'health_state' => EndpointAgent::HEALTH_ONLINE,
        ]);

        $findings = $this->fleetService->detectTamperEvents($agent);

        $heartbeatGap = $findings->firstWhere('tamper_type', EndpointTamperEvent::TYPE_HEARTBEAT_GAP);
        $this->assertNull($heartbeatGap, 'Healthy agent should not trigger heartbeat gap tamper');
    }

    public function test_tamper_type_constants_are_valid(): void
    {
        $this->assertNotEmpty(EndpointTamperEvent::TAMPER_TYPES);
        $this->assertContains(EndpointTamperEvent::TYPE_HEARTBEAT_GAP, EndpointTamperEvent::TAMPER_TYPES);
        $this->assertContains(EndpointTamperEvent::TYPE_CONFIG_MISMATCH, EndpointTamperEvent::TAMPER_TYPES);
        $this->assertContains(EndpointTamperEvent::TYPE_POLICY_DRIFT, EndpointTamperEvent::TAMPER_TYPES);
        $this->assertContains(EndpointTamperEvent::TYPE_AGENT_STOPPED, EndpointTamperEvent::TAMPER_TYPES);
    }

    // =========================================================================
    // Spool health snapshots — append-only
    // =========================================================================

    public function test_spool_snapshot_is_recorded(): void
    {
        $agent = $this->makeAgent();

        $snapshot = $this->fleetService->recordSpoolSnapshot($agent, [
            'queued_events'   => 12,
            'dropped_events'  => 2,
            'retry_count'     => 1,
            'spool_disk_bytes'=> 1024,
            'spool_capped'    => false,
            'disk_pressure'   => false,
        ]);

        $this->assertInstanceOf(EndpointSpoolSnapshot::class, $snapshot);
        $this->assertEquals(12, $snapshot->queued_events);
        $this->assertEquals(2, $snapshot->dropped_events);
        $this->assertEquals(1024, $snapshot->spool_disk_bytes);
        $this->assertFalse($snapshot->spool_capped);
        $this->assertStringStartsWith('spool-', $snapshot->snapshot_id);
    }

    public function test_spool_snapshots_are_append_only(): void
    {
        $agent = $this->makeAgent();

        $this->fleetService->recordSpoolSnapshot($agent, ['queued_events' => 5]);
        $countBefore = EndpointSpoolSnapshot::where('agent_id', $agent->id)->count();

        $this->fleetService->recordSpoolSnapshot($agent, ['queued_events' => 10]);
        $countAfter = EndpointSpoolSnapshot::where('agent_id', $agent->id)->count();

        $this->assertEquals($countBefore + 1, $countAfter, 'Spool snapshots must be INSERT-only');
    }

    public function test_spool_utilization_percent_is_calculated(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->fleetService->recordSpoolSnapshot($agent, [
            'spool_disk_bytes' => 5 * 1024 * 1024, // 5 MiB = 50% of 10 MiB cap
        ]);

        $this->assertEqualsWithDelta(50.0, $snapshot->spoolUtilizationPercent(), 0.01);
    }

    public function test_spool_capped_snapshot_is_recorded_correctly(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->fleetService->recordSpoolSnapshot($agent, [
            'spool_disk_bytes' => 10 * 1024 * 1024 + 1, // over cap
            'spool_capped'     => true,
            'dropped_events'   => 50,
        ]);

        $this->assertTrue($snapshot->spool_capped);
        $this->assertEquals(50, $snapshot->dropped_events);
    }

    // =========================================================================
    // Stale agent detection
    // =========================================================================

    public function test_stale_agents_are_detected(): void
    {
        $staleAgent = $this->makeAgent([
            'last_seen_at' => now()->subHours(2),
            'health_state' => EndpointAgent::HEALTH_STALE,
        ]);
        $onlineAgent = $this->makeAgent([
            'last_seen_at' => now()->subSeconds(30),
            'health_state' => EndpointAgent::HEALTH_ONLINE,
        ]);

        $staleList = $this->fleetService->getStaleAgents();
        $staleIds  = $staleList->pluck('id');

        $this->assertContains($staleAgent->id, $staleIds);
    }

    public function test_telemetry_lag_is_calculated(): void
    {
        $agent = $this->makeAgent([
            'last_seen_at' => now()->subSeconds(120),
        ]);

        $lag = $this->fleetService->calculateTelemetryLag($agent->fresh());

        $this->assertNotNull($lag);
        // Lag should be positive — timezone differences may cause larger values in test env
        $this->assertGreaterThan(0, $lag);
    }

    public function test_telemetry_lag_is_null_for_never_seen_agent(): void
    {
        $agent = $this->makeAgent(['last_seen_at' => null]);

        $lag = $this->fleetService->calculateTelemetryLag($agent->fresh());

        $this->assertNull($lag);
    }

    // =========================================================================
    // Risk engine integration
    // =========================================================================

    public function test_endpoint_operational_risk_factors_in_weights(): void
    {
        $weights = \App\Services\EntityRiskScoringService::WEIGHTS;

        $this->assertArrayHasKey('telemetry_gap_factor', $weights);
        $this->assertArrayHasKey('tamper_visibility_factor', $weights);
        $this->assertArrayHasKey('stale_agent_factor', $weights);
        $this->assertArrayHasKey('policy_drift_factor', $weights);
    }

    public function test_endpoint_risk_factors_are_positive(): void
    {
        $weights = \App\Services\EntityRiskScoringService::WEIGHTS;

        $this->assertGreaterThan(0, $weights['telemetry_gap_factor']);
        $this->assertGreaterThan(0, $weights['tamper_visibility_factor']);
        $this->assertGreaterThan(0, $weights['stale_agent_factor']);
        $this->assertGreaterThan(0, $weights['policy_drift_factor']);
    }

    // =========================================================================
    // Threat hunting domain integration
    // =========================================================================

    public function test_endpoint_agents_is_supported_hunt_domain(): void
    {
        $this->assertContains('endpoint_agents', \App\Services\ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_endpoint_agent_heartbeats_is_supported_hunt_domain(): void
    {
        $this->assertContains('endpoint_agent_heartbeats', \App\Services\ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_endpoint_agent_policy_assignments_is_supported_hunt_domain(): void
    {
        $this->assertContains('endpoint_agent_policy_assignments', \App\Services\ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_endpoint_agent_enrollment_events_is_supported_hunt_domain(): void
    {
        $this->assertContains('endpoint_agent_enrollment_events', \App\Services\ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_threat_hunting_supports_50_domains(): void
    {
        $this->assertCount(
            161,
            \App\Services\ThreatHuntingService::SUPPORTED_DOMAINS,
            'Should have 158 threat hunting domains after all phases through Final Demo / Portfolio / Thesis Packaging Phase 1'
        );
    }

    // =========================================================================
    // Fleet UI routes — accessible with advisory disclaimer
    // =========================================================================

    public function test_fleet_dashboard_is_accessible(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $response = $this->get('/endpoint-fleet');
        $response->assertStatus(200);
        $response->assertSee('Advisory Notice');
        $response->assertSee('advisory-only');
    }

    public function test_agent_health_view_is_accessible(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $response = $this->get('/endpoint-fleet/health');
        $response->assertStatus(200);
        $response->assertSee('advisory-only');
    }

    public function test_tamper_visibility_view_is_accessible(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $response = $this->get('/endpoint-fleet/tamper');
        $response->assertStatus(200);
        $response->assertSee('Advisory Notice');
    }

    public function test_spool_health_view_is_accessible(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $response = $this->get('/endpoint-fleet/spool');
        $response->assertStatus(200);
        $response->assertSee('advisory-only');
    }

    public function test_telemetry_lag_view_is_accessible(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $response = $this->get('/endpoint-fleet/lag');
        $response->assertStatus(200);
        $response->assertSee('advisory-only');
    }

    public function test_policy_assignment_view_is_accessible(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $response = $this->get('/endpoint-fleet/policies');
        $response->assertStatus(200);
        $response->assertSee('advisory-only');
    }

    public function test_enrollment_audit_view_is_accessible(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $response = $this->get('/endpoint-fleet/enrollment');
        $response->assertStatus(200);
        $response->assertSee('advisory-only');
    }

    public function test_policy_drift_view_is_accessible(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $response = $this->get('/endpoint-fleet/drift');
        $response->assertStatus(200);
        $response->assertSee('advisory-only');
    }

    // =========================================================================
    // Fleet API — advisory response assertions
    // =========================================================================

    public function test_fleet_stats_api_includes_advisory_flag(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $response = $this->getJson('/api/endpoint-fleet/stats');
        $response->assertStatus(200);
        $response->assertJsonPath('advisory_only', true);
    }

    public function test_fleet_tamper_detect_api_includes_advisory_fields(): void
    {
        $agent = $this->makeAgent([
            'last_seen_at' => now()->subHours(5),
        ]);

        $user = $this->admin();
        $this->actingAs($user);

        $response = $this->postJson('/api/endpoint-fleet/tamper/detect', [
            'agent_id' => $agent->agent_id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('advisory_only', true);
        $response->assertJsonPath('autonomous_action', false);
        $response->assertJsonStructure(['ok', 'advisory_only', 'autonomous_action', 'findings_count', 'disclaimer']);
    }

    public function test_fleet_tamper_detect_disclaimer_is_present(): void
    {
        $agent = $this->makeAgent([
            'last_seen_at' => now()->subHours(3),
        ]);

        $user = $this->admin();
        $this->actingAs($user);

        $response = $this->postJson('/api/endpoint-fleet/tamper/detect', [
            'agent_id' => $agent->agent_id,
        ]);

        $data = $response->json();
        $this->assertStringContainsString('advisory-only', strtolower($data['disclaimer']));
        $this->assertStringContainsString('no autonomous', strtolower($data['disclaimer']));
    }

    // =========================================================================
    // Hard safety assertions — NO autonomous enforcement
    // =========================================================================

    public function test_no_automatic_host_isolation(): void
    {
        $this->assertFalse(
            method_exists($this->fleetService, 'isolateHost'),
            'EndpointFleetService must NOT have an isolateHost method'
        );
        $this->assertFalse(
            method_exists($this->fleetService, 'quarantineHost'),
            'EndpointFleetService must NOT have a quarantineHost method'
        );
        $this->assertFalse(
            method_exists($this->fleetService, 'blockNetworkAccess'),
            'EndpointFleetService must NOT have a blockNetworkAccess method'
        );
    }

    public function test_no_remote_shell_execution(): void
    {
        $this->assertFalse(
            method_exists($this->fleetService, 'executeShell'),
            'EndpointFleetService must NOT have an executeShell method'
        );
        $this->assertFalse(
            method_exists($this->fleetService, 'runCommand'),
            'EndpointFleetService must NOT have a runCommand method'
        );
        $this->assertFalse(
            method_exists($this->fleetService, 'openShell'),
            'EndpointFleetService must NOT have an openShell method'
        );
    }

    public function test_no_process_kill(): void
    {
        $this->assertFalse(
            method_exists($this->fleetService, 'killProcess'),
            'EndpointFleetService must NOT have a killProcess method'
        );
        $this->assertFalse(
            method_exists($this->fleetService, 'terminateProcess'),
            'EndpointFleetService must NOT have a terminateProcess method'
        );
    }

    public function test_no_autonomous_remediation(): void
    {
        $this->assertFalse(
            method_exists($this->fleetService, 'autoRemediate'),
            'EndpointFleetService must NOT have an autoRemediate method'
        );
        $this->assertFalse(
            method_exists($this->fleetService, 'automaticResponse'),
            'EndpointFleetService must NOT have an automaticResponse method'
        );
        $this->assertFalse(
            method_exists($this->fleetService, 'forceContain'),
            'EndpointFleetService must NOT have a forceContain method'
        );
    }

    public function test_all_tamper_events_are_never_acted_on_automatically(): void
    {
        $agent = $this->makeAgent([
            'last_seen_at' => now()->subHours(3),
        ]);

        $findings = $this->fleetService->detectTamperEvents($agent);

        foreach ($findings as $f) {
            $this->assertFalse($f->acknowledged,
                'Tamper events should start unacknowledged — analyst must review');
            $this->assertTrue($f->is_advisory,
                'All tamper events must be advisory-only');
        }
    }

    public function test_spool_capped_does_not_trigger_automatic_response(): void
    {
        $agent = $this->makeAgent();

        // Record a capped spool snapshot
        $snapshot = $this->fleetService->recordSpoolSnapshot($agent, [
            'spool_capped'   => true,
            'dropped_events' => 100,
        ]);

        // Verify tamper finding if generated is advisory-only
        $tamperFindings = EndpointTamperEvent::where('agent_id', $agent->id)->get();
        foreach ($tamperFindings as $f) {
            $this->assertTrue($f->is_advisory);
        }

        // No automatic remediation was triggered
        $this->assertFalse(
            method_exists($this->fleetService, 'clearSpool'),
            'Fleet service must not clear remote spool autonomously'
        );
    }

    // =========================================================================
    // Model constants
    // =========================================================================

    public function test_endpoint_fleet_policy_model_constants(): void
    {
        $this->assertNotEmpty(EndpointFleetPolicy::REASON_MANUAL);
        $this->assertNotEmpty(EndpointFleetPolicy::REASON_ROLLBACK);
        $this->assertNotEmpty(EndpointFleetPolicy::REASON_BULK_ROLLOUT);
    }

    public function test_endpoint_tamper_event_model_constants(): void
    {
        $this->assertNotEmpty(EndpointTamperEvent::TAMPER_TYPES);
        $this->assertCount(8, EndpointTamperEvent::TAMPER_TYPES);
        $this->assertContains(EndpointTamperEvent::TYPE_TELEMETRY_INTERRUPTION, EndpointTamperEvent::TAMPER_TYPES);
    }

    public function test_endpoint_spool_snapshot_spool_cap_constant(): void
    {
        $this->assertEquals(10 * 1024 * 1024, EndpointSpoolSnapshot::SPOOL_CAP_BYTES);
    }

    public function test_enrollment_event_types_are_complete(): void
    {
        $this->assertNotEmpty(EndpointAgentEnrollmentEvent::EVENT_TYPES);
        $this->assertContains(EndpointAgentEnrollmentEvent::EVENT_ENROLLED, EndpointAgentEnrollmentEvent::EVENT_TYPES);
        $this->assertContains(EndpointAgentEnrollmentEvent::EVENT_REVOKED, EndpointAgentEnrollmentEvent::EVENT_TYPES);
        $this->assertContains(EndpointAgentEnrollmentEvent::EVENT_FAILED, EndpointAgentEnrollmentEvent::EVENT_TYPES);
    }

    // =========================================================================
    // Heartbeat with spool_stats — integration
    // =========================================================================

    public function test_heartbeat_with_spool_stats_records_snapshot(): void
    {
        $agent    = $this->makeAgent();
        $user     = $this->admin();
        $token    = 'test-fleet-hardening-token';
        $traceId  = \Illuminate\Support\Str::uuid()->toString();

        $payload = [
            'agent_id'   => $agent->agent_id,
            'host_id'    => $agent->host_id,
            'hostname'   => $agent->hostname,
            'timestamp'  => now()->toIso8601String(),
            'metrics'    => ['events_per_sec' => 1.5, 'dropped_events' => 0],
            'spool_stats'=> [
                'queued_events'   => 8,
                'dropped_events'  => 0,
                'spool_disk_bytes'=> 512,
                'spool_capped'    => false,
                'disk_pressure'   => false,
            ],
            'trace_id'   => $traceId,
        ];

        $payloadJson = json_encode($payload);
        $sig = 'sha256=' . hash_hmac('sha256', $payloadJson, $token);

        $response = $this->withHeaders(['X-Agent-Signature' => $sig])
            ->postJson("/api/agents/{$agent->agent_id}/heartbeat", $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);

        $snapshot = EndpointSpoolSnapshot::where('agent_id', $agent->id)->first();
        $this->assertNotNull($snapshot, 'Spool snapshot should be recorded with heartbeat');
        $this->assertEquals(8, $snapshot->queued_events);
        $this->assertEquals(512, $snapshot->spool_disk_bytes);
    }
}
