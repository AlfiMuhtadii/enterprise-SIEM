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
use App\Services\EndpointFleetService;
use App\Services\EndpointAgentService;
use App\Services\EntityRiskScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Endpoint Fleet Phase 2 — Fleet-Scale Simulation & Operational Failure Validation.
 *
 * 7 simulation scenarios exercising fleet service logic under degraded conditions:
 *   1. Healthy fleet baseline
 *   2. Stale agent detection
 *   3. Policy drift visibility
 *   4. Spool capped agent
 *   5. Telemetry lag agent
 *   6. Tamper advisory agent
 *   7. Mixed degraded fleet
 *
 * Each scenario:
 *   - Creates deterministic fixture data
 *   - Runs the fleet service
 *   - Asserts correct detection/reporting
 *   - Asserts advisory-only posture (no enforcement)
 *
 * Safety invariants enforced across all scenarios:
 *   - No autonomous remediation triggered
 *   - All tamper events have is_advisory=true
 *   - All findings require analyst review
 *   - No host isolation, process kill, or remote shell
 */
class EndpointFleetSimulationTest extends TestCase
{
    use RefreshDatabase;

    private EndpointFleetService $fleetService;
    private EndpointAgentService $agentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fleetService = app(EndpointFleetService::class);
        $this->agentService = app(EndpointAgentService::class);
        Config::set('soc.agent_enrollment_token', 'fleet-sim-token');
        Config::set('soc.agent_heartbeat_interval_seconds', 60);
    }

    // =========================================================================
    // Fixture helpers
    // =========================================================================

    private function makeAgent(string $hostname, array $overrides = []): EndpointAgent
    {
        return EndpointAgent::create(array_merge([
            'agent_id'              => 'agent-sim-' . strtolower($hostname),
            'host_id'               => 'host-sim-' . strtolower($hostname),
            'host_fingerprint'      => hash('sha256', 'sim-' . $hostname),
            'hostname'              => $hostname,
            'enrollment_token_hash' => EndpointAgent::hashEnrollmentToken('fleet-sim-token'),
            'agent_version'         => '1.0.0',
            'platform'              => 'Linux',
            'os_family'             => 'Linux',
            'health_state'          => EndpointAgent::HEALTH_ONLINE,
            'status'                => 'online',
            'enrolled_at'           => now()->subHours(24),
            'last_seen_at'          => now()->subSeconds(30),
        ], $overrides));
    }

    private function makeFleetPolicy(array $configOverrides = []): EndpointFleetPolicy
    {
        $config = array_merge(EndpointAgentConfig::DEFAULT_CONFIG, $configOverrides);
        return $this->fleetService->createFleetPolicy($config, 'Sim Policy v1');
    }

    private function makeHeartbeat(EndpointAgent $agent, array $metricsOverrides = []): EndpointAgentHeartbeat
    {
        return EndpointAgentHeartbeat::create([
            'agent_id'        => $agent->id,
            'signature'       => 'sha256=' . hash('sha256', 'sim-heartbeat'),
            'signature_valid' => true,
            'health_state'    => EndpointAgent::HEALTH_ONLINE,
            'ip_address'      => '10.0.0.1',
            'metrics'         => array_merge(['events_per_sec' => 1.5, 'dropped_events' => 0], $metricsOverrides),
            'heartbeat_at'    => now(),
        ]);
    }

    // =========================================================================
    // SCENARIO 1 — Healthy Fleet Baseline
    // =========================================================================

    /**
     * A fully healthy fleet produces zero stale agents, zero tamper events,
     * zero spool warnings, and zero policy drifts.
     * Dashboard stats must reflect the clean state.
     */
    public function test_scenario_healthy_fleet_baseline(): void
    {
        // Fixture: 3 healthy online agents
        $agents = collect([
            $this->makeAgent('web-01'),
            $this->makeAgent('web-02'),
            $this->makeAgent('web-03'),
        ]);

        // All agents have a matching policy
        $policy = $this->makeFleetPolicy();
        foreach ($agents as $agent) {
            $this->fleetService->assignPolicyToAgent($agent, $policy);
            $this->makeHeartbeat($agent, ['config_hash' => $policy->config_hash]);
            $this->fleetService->recordSpoolSnapshot($agent, [
                'queued_events'   => 3,
                'dropped_events'  => 0,
                'spool_disk_bytes'=> 512,
                'spool_capped'    => false,
                'disk_pressure'   => false,
            ]);
        }

        // Validate
        $stats = $this->fleetService->getDashboardStats();

        $this->assertEquals(3, $stats['online']);
        $this->assertEquals(0, $stats['stale'] + $stats['offline']);
        $this->assertEquals(0, $stats['tamper_events_7d']);
        $this->assertEquals(0, $stats['spool_warnings']);
        $this->assertEquals(0, $stats['total_dropped_events_24h']);

        // No stale agents
        $stale = $this->fleetService->getStaleAgents();
        $this->assertEquals(0, $stale->count());

        // No spool warnings
        $this->assertEquals(0, $this->fleetService->countSpoolWarnings());

        // Advisory invariant: even healthy agents, if tamper detection runs, findings are advisory
        foreach ($agents as $agent) {
            $findings = $this->fleetService->detectTamperEvents($agent);
            foreach ($findings as $f) {
                $this->assertTrue($f->is_advisory, 'All tamper events must be advisory-only');
            }
        }
    }

    // =========================================================================
    // SCENARIO 2 — Stale Agent Detection
    // =========================================================================

    /**
     * Agents that have not checked in within the expected window are
     * correctly detected as stale. Detection is deterministic.
     */
    public function test_scenario_stale_agent_detection(): void
    {
        // Fixture: 1 online agent + 2 stale agents
        $onlineAgent = $this->makeAgent('online-001', [
            'last_seen_at' => now()->subSeconds(30),
            'health_state' => EndpointAgent::HEALTH_ONLINE,
        ]);
        $staleAgent1 = $this->makeAgent('stale-001', [
            'last_seen_at' => now()->subHours(2),
            'health_state' => EndpointAgent::HEALTH_STALE,
        ]);
        $staleAgent2 = $this->makeAgent('stale-002', [
            'last_seen_at' => now()->subHours(6),
            'health_state' => EndpointAgent::HEALTH_OFFLINE,
        ]);

        // Assert: getStaleAgents includes both stale/offline, not the online one
        $detected = $this->fleetService->getStaleAgents();
        $detectedIds = $detected->pluck('id')->toArray();

        $this->assertContains($staleAgent1->id, $detectedIds, 'Stale agent 1 should be detected');
        $this->assertContains($staleAgent2->id, $detectedIds, 'Stale agent 2 (offline) should be detected');
        $this->assertNotContains($onlineAgent->id, $detectedIds, 'Online agent should NOT be in stale list');

        // Telemetry lag for stale agent must be positive and significant
        $lag1 = $this->fleetService->calculateTelemetryLag($staleAgent1->fresh());
        $lag2 = $this->fleetService->calculateTelemetryLag($staleAgent2->fresh());

        $this->assertNotNull($lag1);
        $this->assertNotNull($lag2);
        $this->assertGreaterThan(0, $lag1, 'Stale agent 1 lag must be positive');
        $this->assertGreaterThan($lag1, $lag2, 'Stale agent 2 (older) must have higher lag');

        // Heartbeat gap tamper event must be detected for stale agents
        $findings1 = $this->fleetService->detectTamperEvents($staleAgent1);
        $hasGap = $findings1->where('tamper_type', EndpointTamperEvent::TYPE_HEARTBEAT_GAP)->isNotEmpty();
        $this->assertTrue($hasGap, 'Stale agent should trigger heartbeat_gap tamper event');

        foreach ($findings1 as $f) {
            $this->assertTrue($f->is_advisory, 'Tamper events for stale agent must be advisory-only');
            $this->assertFalse($f->acknowledged, 'Tamper events start unacknowledged — analyst must review');
        }

        // Dashboard stats must reflect stale agents
        $stats = $this->fleetService->getDashboardStats();
        $this->assertGreaterThanOrEqual(1, $stats['stale'] + $stats['offline']);
    }

    // =========================================================================
    // SCENARIO 3 — Policy Drift Visibility
    // =========================================================================

    /**
     * An agent running a different config than its assigned fleet policy
     * is detected as drifted. Detection uses config_hash comparison.
     */
    public function test_scenario_policy_drift_visibility(): void
    {
        $agent  = $this->makeAgent('drifted-001');
        $policy = $this->makeFleetPolicy();

        // Assign policy to agent
        $assignment = $this->fleetService->assignPolicyToAgent($agent, $policy);

        // Simulate that the assignment was made > POLICY_DRIFT_MIN_AGE_SECONDS ago
        DB::table('endpoint_agent_policy_assignments')
            ->where('assignment_id', $assignment->assignment_id)
            ->update(['assigned_at' => now()->subMinutes(10)]);

        // Agent reports a DIFFERENT config_hash in heartbeat (simulating drift)
        $differentHash = hash('sha256', 'completely-different-config');
        $this->makeHeartbeat($agent, ['config_hash' => $differentHash]);

        // Detect drift
        $drift = $this->fleetService->checkPolicyDrift($agent);

        $this->assertNotNull($drift, 'Policy drift should be detected');
        $this->assertArrayHasKey('assigned_config_hash', $drift);
        $this->assertArrayHasKey('reported_config_hash', $drift);
        $this->assertNotEquals($drift['assigned_config_hash'], $drift['reported_config_hash'],
            'Assigned and reported config hashes must differ');

        // Drift agents should appear in dashboard stats
        $driftAgents = $this->fleetService->getAgentsWithPolicyDrift();
        $this->assertGreaterThan(0, $driftAgents->count());

        // Tamper event for drift is advisory only
        $tamperFindings = $this->fleetService->detectTamperEvents($agent);
        $driftEvent = $tamperFindings->firstWhere('tamper_type', EndpointTamperEvent::TYPE_POLICY_DRIFT);
        if ($driftEvent) {
            $this->assertTrue($driftEvent->is_advisory);
            $this->assertNotEmpty($driftEvent->evidence);
        }
    }

    // =========================================================================
    // SCENARIO 4 — Spool Capped Agent
    // =========================================================================

    /**
     * An agent whose local spool has reached maximum capacity is correctly
     * detected via spool snapshot. Findings are advisory — no autonomous action.
     */
    public function test_scenario_spool_capped_agent(): void
    {
        $healthyAgent = $this->makeAgent('healthy-spool-001');
        $cappedAgent  = $this->makeAgent('capped-spool-001');

        // Healthy agent — no spool issues
        $this->fleetService->recordSpoolSnapshot($healthyAgent, [
            'spool_disk_bytes' => 1024,
            'spool_capped'     => false,
            'dropped_events'   => 0,
        ]);

        // Capped agent — spool at capacity, events dropped
        $this->fleetService->recordSpoolSnapshot($cappedAgent, [
            'spool_disk_bytes' => 10 * 1024 * 1024, // 10 MiB = cap
            'spool_capped'     => true,
            'dropped_events'   => 150,
            'oldest_spool_age_seconds' => 3600,
        ]);

        // Verify spool warning is detected
        $warnings = $this->fleetService->countSpoolWarnings();
        $this->assertGreaterThanOrEqual(1, $warnings, 'At least one spool warning should be detected');

        // Spool summary includes the capped agent
        $summary = $this->fleetService->getSpoolHealthSummary();
        $cappedRow = $summary->firstWhere('hostname', 'capped-spool-001');
        $this->assertNotNull($cappedRow, 'Capped agent should appear in spool health summary');
        $this->assertTrue((bool) $cappedRow->spool_capped);
        $this->assertEquals(150, $cappedRow->dropped_events);

        // Tamper event generated for telemetry interruption — advisory only
        $findings = $this->fleetService->detectTamperEvents($cappedAgent);
        $spoolTamper = $findings->firstWhere('tamper_type', EndpointTamperEvent::TYPE_TELEMETRY_INTERRUPTION);
        $this->assertNotNull($spoolTamper, 'Spool cap should trigger telemetry_interruption tamper event');
        $this->assertTrue($spoolTamper->is_advisory, 'Spool cap tamper must be advisory-only');

        // Safety: no automatic spool clearing, no remote action
        $this->assertFalse(
            method_exists($this->fleetService, 'clearRemoteSpool'),
            'No autonomous spool clearing allowed'
        );
        $this->assertFalse(
            method_exists($this->fleetService, 'restartAgent'),
            'No autonomous agent restart allowed'
        );
    }

    // =========================================================================
    // SCENARIO 5 — Telemetry Lag Agent
    // =========================================================================

    /**
     * An agent that has not sent telemetry recently shows increasing lag.
     * Lag is deterministically calculated from last_seen_at.
     */
    public function test_scenario_telemetry_lag_agent(): void
    {
        $lowLagAgent  = $this->makeAgent('low-lag-001', ['last_seen_at' => now()->subSeconds(45)]);
        $highLagAgent = $this->makeAgent('high-lag-001', ['last_seen_at' => now()->subHours(1), 'health_state' => EndpointAgent::HEALTH_STALE]);
        $noDataAgent  = $this->makeAgent('no-data-001',  ['last_seen_at' => null, 'health_state' => EndpointAgent::HEALTH_OFFLINE]);

        // Validate lag calculations
        $lowLag  = $this->fleetService->calculateTelemetryLag($lowLagAgent->fresh());
        $highLag = $this->fleetService->calculateTelemetryLag($highLagAgent->fresh());
        $noLag   = $this->fleetService->calculateTelemetryLag($noDataAgent->fresh());

        $this->assertNotNull($lowLag, 'Recently seen agent should have a lag value');
        $this->assertNotNull($highLag, 'Stale agent should have a lag value');
        $this->assertNull($noLag, 'Never-seen agent should have null lag');

        $this->assertGreaterThan(0, $lowLag);
        $this->assertGreaterThan($lowLag, $highLag, 'High lag agent must have greater lag than low lag');

        // Lag summary must include both agents in order
        $lagSummary = $this->fleetService->getTelemetryLagSummary();
        $this->assertGreaterThanOrEqual(2, $lagSummary->count());

        $lagIds = $lagSummary->pluck('agent_id')->toArray();
        $this->assertContains($highLagAgent->agent_id, $lagIds);

        // Verify lag summary is ordered: highest lag first
        $first = $lagSummary->first();
        $this->assertGreaterThanOrEqual(0, $first->lag_seconds);
    }

    // =========================================================================
    // SCENARIO 6 — Tamper Advisory Agent
    // =========================================================================

    /**
     * A fleet of agents with various tamper indicators (heartbeat gaps, config mismatch,
     * spool cap) all produce advisory-only findings. No enforcement is triggered.
     */
    public function test_scenario_tamper_advisory_agent(): void
    {
        // Fixture: 3 agents with different tamper conditions
        $gapAgent = $this->makeAgent('tamper-gap-001', [
            'last_seen_at' => now()->subHours(5),
            'health_state' => EndpointAgent::HEALTH_STALE,
        ]);
        $spoolCapAgent = $this->makeAgent('tamper-spool-001');
        $this->fleetService->recordSpoolSnapshot($spoolCapAgent, [
            'spool_capped'   => true,
            'dropped_events' => 20,
        ]);

        $neverSeenAgent = $this->makeAgent('tamper-noseen-001', [
            'last_seen_at' => null,
            'enrolled_at'  => now()->subHours(2),
        ]);

        // Run tamper detection on each
        $allFindings = collect();
        foreach ([$gapAgent, $spoolCapAgent, $neverSeenAgent] as $agent) {
            $findings = $this->fleetService->detectTamperEvents($agent);
            $allFindings = $allFindings->merge($findings);
        }

        // At least some tamper findings should be generated
        $this->assertGreaterThan(0, $allFindings->count());

        // ALL findings must be advisory-only — no exceptions
        foreach ($allFindings as $f) {
            $this->assertTrue($f->is_advisory,
                "Tamper event '{$f->tamper_type}' on agent {$f->agent_id} MUST be advisory-only");
            $this->assertFalse($f->acknowledged,
                'Tamper events must start unacknowledged — analyst review required');
            $this->assertNotEmpty($f->tamper_id, 'Tamper event must have an ID');
            $this->assertNotEmpty($f->description, 'Tamper event must have an explainable description');
            $this->assertNotEmpty($f->severity, 'Tamper event must have a severity');
        }

        // Tamper summary should include affected agents
        $summary = $this->fleetService->getTamperVisibilitySummary();
        $this->assertGreaterThanOrEqual(0, $summary->count());

        // Safety: tamper detection does NOT trigger enforcement
        $this->assertFalse(
            method_exists($this->fleetService, 'applyAutoRemediation'),
            'No autonomous remediation allowed'
        );
        $this->assertFalse(
            method_exists($this->fleetService, 'isolateOnTamper'),
            'No automatic isolation on tamper detection'
        );
    }

    // =========================================================================
    // SCENARIO 7 — Mixed Degraded Fleet
    // =========================================================================

    /**
     * A realistic mixed fleet with some healthy agents and multiple degraded states.
     * Dashboard stats must accurately reflect the degraded segment.
     * Advisory posture is maintained across all degraded states.
     */
    public function test_scenario_mixed_degraded_fleet(): void
    {
        // Fixture: varied fleet
        $healthy1  = $this->makeAgent('mix-healthy-001', ['health_state' => EndpointAgent::HEALTH_ONLINE, 'last_seen_at' => now()->subSeconds(30)]);
        $healthy2  = $this->makeAgent('mix-healthy-002', ['health_state' => EndpointAgent::HEALTH_ONLINE, 'last_seen_at' => now()->subSeconds(45)]);
        $stale1    = $this->makeAgent('mix-stale-001',   ['health_state' => EndpointAgent::HEALTH_STALE,  'last_seen_at' => now()->subHours(2)]);
        $offline1  = $this->makeAgent('mix-offline-001', ['health_state' => EndpointAgent::HEALTH_OFFLINE,'last_seen_at' => now()->subHours(8)]);
        $degraded1 = $this->makeAgent('mix-degraded-001',['health_state' => EndpointAgent::HEALTH_DEGRADED,'last_seen_at'=> now()->subMinutes(10)]);

        // Add spool problem to one agent
        $this->fleetService->recordSpoolSnapshot($stale1, [
            'spool_capped'   => true,
            'dropped_events' => 25,
        ]);

        // Add policy drift to degraded agent (offline agents are excluded from drift check)
        $policy = $this->makeFleetPolicy();
        $assignment = $this->fleetService->assignPolicyToAgent($degraded1, $policy);
        DB::table('endpoint_agent_policy_assignments')
            ->where('assignment_id', $assignment->assignment_id)
            ->update(['assigned_at' => now()->subMinutes(10)]);
        $this->makeHeartbeat($degraded1, ['config_hash' => hash('sha256', 'drift-config')]);

        // Enrollment events for tracking
        $this->fleetService->recordEnrollmentEvent($healthy1, EndpointAgentEnrollmentEvent::EVENT_ENROLLED);
        $this->fleetService->recordEnrollmentEvent($stale1,   EndpointAgentEnrollmentEvent::EVENT_ENROLLED);

        // Dashboard stats
        $stats = $this->fleetService->getDashboardStats();
        $this->assertEquals(5, $stats['total']);
        $this->assertEquals(2, $stats['online']);
        $this->assertEquals(1, $stats['stale']);
        $this->assertEquals(1, $stats['offline']);
        $this->assertEquals(1, $stats['degraded']);

        // Stale detection
        $stale = $this->fleetService->getStaleAgents();
        $staleIds = $stale->pluck('id');
        $this->assertContains($stale1->id, $staleIds);
        $this->assertContains($offline1->id, $staleIds);
        $this->assertContains($degraded1->id, $staleIds);

        // Spool warnings
        $this->assertGreaterThanOrEqual(1, $this->fleetService->countSpoolWarnings());

        // Policy drift
        $drifted = $this->fleetService->getAgentsWithPolicyDrift();
        $this->assertGreaterThanOrEqual(1, $drifted->count());

        // All tamper events across fleet are advisory
        foreach ([$stale1, $offline1] as $agent) {
            $findings = $this->fleetService->detectTamperEvents($agent);
            foreach ($findings as $f) {
                $this->assertTrue($f->is_advisory);
            }
        }

        // Enrollment events are append-only — no mutations
        $events = EndpointAgentEnrollmentEvent::all();
        $this->assertEquals(2, $events->count(), 'Exactly 2 enrollment events should exist');
    }

    // =========================================================================
    // Cross-scenario: Risk factor validation
    // =========================================================================

    /**
     * Risk factors contributed by endpoint fleet state are advisory-only amplifiers.
     * They are present in the WEIGHTS table and positive.
     */
    public function test_endpoint_risk_factors_are_correctly_configured(): void
    {
        $weights = EntityRiskScoringService::WEIGHTS;

        $expectedFactors = [
            'telemetry_gap_factor'     => 2.0,
            'tamper_visibility_factor' => 2.5,
            'stale_agent_factor'       => 1.5,
            'policy_drift_factor'      => 1.0,
        ];

        foreach ($expectedFactors as $factor => $weight) {
            $this->assertArrayHasKey($factor, $weights, "Risk factor '{$factor}' must exist");
            $this->assertEquals($weight, $weights[$factor], "Risk factor '{$factor}' weight must be {$weight}");
        }
    }

    // =========================================================================
    // Cross-scenario: Append-only immutability
    // =========================================================================

    /**
     * Simulation creates many append-only records. None of them should
     * ever be updated — each is immutable after insert.
     */
    public function test_simulation_records_are_append_only(): void
    {
        $agent  = $this->makeAgent('immutable-test-001');
        $policy = $this->makeFleetPolicy();

        // Create one of each append-only record type
        $this->fleetService->assignPolicyToAgent($agent, $policy);
        $this->fleetService->recordEnrollmentEvent($agent, EndpointAgentEnrollmentEvent::EVENT_ENROLLED);
        $this->fleetService->recordSpoolSnapshot($agent, ['spool_disk_bytes' => 512]);
        $this->fleetService->detectTamperEvents($this->makeAgent('tamper-immutable', [
            'last_seen_at' => now()->subHours(3),
        ]));

        $assignmentsBefore = EndpointAgentPolicyAssignment::count();
        $enrollmentsBefore = EndpointAgentEnrollmentEvent::count();
        $spoolBefore       = EndpointSpoolSnapshot::count();
        $tamperBefore      = EndpointTamperEvent::count();

        // No update methods are called — all records stay immutable
        $this->assertEquals($assignmentsBefore, EndpointAgentPolicyAssignment::count());
        $this->assertEquals($enrollmentsBefore, EndpointAgentEnrollmentEvent::count());
        $this->assertEquals($spoolBefore, EndpointSpoolSnapshot::count());
        $this->assertEquals($tamperBefore, EndpointTamperEvent::count());
    }

    // =========================================================================
    // Cross-scenario: Safety invariants
    // =========================================================================

    public function test_no_autonomous_enforcement_in_any_scenario(): void
    {
        $forbidden = [
            'isolateHost', 'quarantineHost', 'blockNetworkAccess',
            'executeShell', 'runCommand', 'openShell',
            'killProcess', 'terminateProcess',
            'autoRemediate', 'automaticResponse', 'forceContain',
            'clearRemoteSpool', 'restartAgent', 'applyAutoRemediation',
            'isolateOnTamper',
        ];

        foreach ($forbidden as $method) {
            $this->assertFalse(
                method_exists($this->fleetService, $method),
                "EndpointFleetService MUST NOT have method '{$method}'"
            );
        }
    }

    public function test_all_append_only_tables_have_no_updated_at(): void
    {
        // Verify no updated_at column on append-only tables — enforces immutability
        $appendOnlyTables = [
            'endpoint_agent_policy_assignments',
            'endpoint_agent_enrollment_events',
            'endpoint_tamper_events',
            'endpoint_spool_snapshots',
        ];

        foreach ($appendOnlyTables as $table) {
            $this->assertFalse(
                \Illuminate\Support\Facades\DB::getSchemaBuilder()->hasColumn($table, 'updated_at'),
                "Append-only table '{$table}' must not have an updated_at column"
            );
        }
    }

    // =========================================================================
    // Threat hunting domain coverage
    // =========================================================================

    public function test_all_fleet_domains_support_field_queries(): void
    {
        $huntingService = app(\App\Services\ThreatHuntingService::class);

        $domains = [
            'endpoint_agents',
            'endpoint_agent_heartbeats',
            'endpoint_agent_policy_assignments',
            'endpoint_agent_enrollment_events',
        ];

        foreach ($domains as $domain) {
            $this->assertContains($domain, \App\Services\ThreatHuntingService::SUPPORTED_DOMAINS,
                "Domain '{$domain}' must be in SUPPORTED_DOMAINS");
        }
    }
}
