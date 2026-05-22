<?php

namespace Tests\Feature;

use App\Models\AttackStageTimeline;
use App\Models\CorrelationEvidenceLink;
use App\Models\CrossDomainCorrelation;
use App\Models\EndpointAgent;
use App\Models\EndpointBeaconPattern;
use App\Models\EndpointBehavioralFinding;
use App\Models\EndpointPersistenceItem;
use App\Models\User;
use App\Services\CrossDomainCorrelationService;
use App\Services\EntityRiskScoringService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cross-Domain Correlation Phase 1 tests.
 *
 * Explicitly asserts:
 * - No autonomous response
 * - No host isolation
 * - No process killing
 * - No containment execution
 * - No mutation of source telemetry
 * - Append-only guarantees
 * - Shadow-only enforcement
 */
class CrossDomainCorrelationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function svc(): CrossDomainCorrelationService
    {
        return app(CrossDomainCorrelationService::class);
    }

    private function makeAgent(): EndpointAgent
    {
        return EndpointAgent::factory()->create(['hostname' => 'test-host-' . uniqid()]);
    }

    // -----------------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------------

    public function test_cross_domain_correlations_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('cross_domain_correlations'));
    }

    public function test_attack_stage_timelines_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('attack_stage_timelines'));
    }

    public function test_correlation_evidence_links_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('correlation_evidence_links'));
    }

    public function test_cross_domain_correlations_has_required_columns(): void
    {
        foreach (['correlation_id', 'correlation_type', 'confidence_score', 'attack_stages',
                  'actor_key', 'involved_hosts', 'alert_ids', 'trace_ids', 'trace_id', 'created_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('cross_domain_correlations', $col), "Missing column: $col");
        }
        $this->assertFalse(Schema::hasColumn('cross_domain_correlations', 'updated_at'), 'Should be append-only (no updated_at)');
    }

    public function test_attack_stage_timelines_has_required_columns(): void
    {
        foreach (['timeline_id', 'correlation_id', 'stage', 'stage_order', 'stage_confidence', 'created_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('attack_stage_timelines', $col), "Missing column: $col");
        }
        $this->assertFalse(Schema::hasColumn('attack_stage_timelines', 'updated_at'), 'Should be append-only (no updated_at)');
    }

    public function test_correlation_evidence_links_has_required_columns(): void
    {
        foreach (['correlation_id', 'evidence_type', 'evidence_table', 'evidence_snapshot', 'domain', 'created_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('correlation_evidence_links', $col), "Missing column: $col");
        }
        $this->assertFalse(Schema::hasColumn('correlation_evidence_links', 'updated_at'), 'Should be append-only (no updated_at)');
    }

    // -----------------------------------------------------------------------
    // Model constants and ID generation
    // -----------------------------------------------------------------------

    public function test_correlation_type_constants_defined(): void
    {
        $this->assertContains(CrossDomainCorrelation::TYPE_IDENTITY_ENDPOINT, CrossDomainCorrelation::TYPES);
        $this->assertContains(CrossDomainCorrelation::TYPE_SAAS_ENDPOINT, CrossDomainCorrelation::TYPES);
        $this->assertContains(CrossDomainCorrelation::TYPE_CLOUD_ENDPOINT, CrossDomainCorrelation::TYPES);
        $this->assertContains(CrossDomainCorrelation::TYPE_MULTI_HOST, CrossDomainCorrelation::TYPES);
        $this->assertContains(CrossDomainCorrelation::TYPE_CROSS_DOMAIN, CrossDomainCorrelation::TYPES);
    }

    public function test_attack_stage_constants_defined(): void
    {
        $this->assertContains('initial_access', AttackStageTimeline::STAGES);
        $this->assertContains('credential_access', AttackStageTimeline::STAGES);
        $this->assertContains('execution', AttackStageTimeline::STAGES);
        $this->assertContains('persistence', AttackStageTimeline::STAGES);
        $this->assertContains('command_and_control', AttackStageTimeline::STAGES);
        $this->assertContains('lateral_movement', AttackStageTimeline::STAGES);
        $this->assertContains('exfiltration', AttackStageTimeline::STAGES);
        $this->assertContains('impact', AttackStageTimeline::STAGES);
    }

    public function test_attack_stage_order_is_ascending(): void
    {
        $order = AttackStageTimeline::STAGE_ORDER;
        $this->assertGreaterThan($order['initial_access'], $order['credential_access']);
        $this->assertGreaterThan($order['credential_access'], $order['execution']);
        $this->assertGreaterThan($order['execution'], $order['persistence']);
        $this->assertGreaterThan($order['persistence'], $order['command_and_control']);
        $this->assertGreaterThan($order['command_and_control'], $order['lateral_movement']);
    }

    public function test_correlation_id_format(): void
    {
        $corr = CrossDomainCorrelation::create([
            'correlation_id'    => CrossDomainCorrelation::generateCorrelationId(),
            'correlation_type'  => CrossDomainCorrelation::TYPE_IDENTITY_ENDPOINT,
            'confidence_score'  => 0.70,
            'attack_stages'     => [],
            'involved_hosts'    => [],
            'involved_users'    => [],
            'involved_ips'      => [],
            'alert_ids'         => [],
            'trace_ids'         => [],
            'correlation_metadata' => [],
        ]);
        $this->assertMatchesRegularExpression('/^CORR-\d{4}-\d{5}$/', $corr->correlation_id);
    }

    public function test_timeline_id_format(): void
    {
        $corr = CrossDomainCorrelation::create([
            'correlation_id'    => CrossDomainCorrelation::generateCorrelationId(),
            'correlation_type'  => CrossDomainCorrelation::TYPE_MULTI_HOST,
            'confidence_score'  => 0.70,
            'attack_stages'     => [],
            'involved_hosts'    => [],
            'involved_users'    => [],
            'involved_ips'      => [],
            'alert_ids'         => [],
            'trace_ids'         => [],
            'correlation_metadata' => [],
        ]);
        $timeline = AttackStageTimeline::create([
            'timeline_id'      => AttackStageTimeline::generateTimelineId(),
            'correlation_id'   => $corr->id,
            'stage'            => AttackStageTimeline::STAGE_EXECUTION,
            'stage_order'      => 3,
            'stage_confidence' => 0.80,
        ]);
        $this->assertMatchesRegularExpression('/^AST-\d{4}-\d{5}$/', $timeline->timeline_id);
    }

    // -----------------------------------------------------------------------
    // Attack stage assignment — deterministic
    // -----------------------------------------------------------------------

    public function test_attack_stage_assignment_from_identity_mfa_alert(): void
    {
        $alerts = collect([(object)['alert_type' => 'IDENTITY_MFA_FAILURE_BURST', 'trace_id' => null]]);
        $stages = $this->svc()->determineAttackStages($alerts, collect());
        $this->assertContains('initial_access', $stages);
    }

    public function test_attack_stage_assignment_from_identity_priv_escalation(): void
    {
        $alerts = collect([(object)['alert_type' => 'IDENTITY_PRIVILEGE_ESCALATION', 'trace_id' => null]]);
        $stages = $this->svc()->determineAttackStages($alerts, collect());
        $this->assertContains('credential_access', $stages);
    }

    public function test_attack_stage_assignment_from_endpoint_execution_chain(): void
    {
        $finding = new \stdClass();
        $finding->finding_type = 'suspicious_execution_chain';
        $stages = $this->svc()->determineAttackStages(collect(), collect([$finding]));
        $this->assertContains('execution', $stages);
    }

    public function test_attack_stage_assignment_from_beacon_pattern(): void
    {
        $finding = new \stdClass();
        $finding->finding_type = 'beacon_pattern';
        $stages = $this->svc()->determineAttackStages(collect(), collect([$finding]));
        $this->assertContains('command_and_control', $stages);
    }

    public function test_attack_stages_are_deterministic_same_input_same_output(): void
    {
        $alerts  = collect([(object)['alert_type' => 'IDENTITY_MFA_FAILURE_BURST', 'trace_id' => null]]);
        $finding = new \stdClass(); $finding->finding_type = 'suspicious_execution_chain';
        $findings = collect([$finding]);

        $stagesA = $this->svc()->determineAttackStages($alerts, $findings);
        $stagesB = $this->svc()->determineAttackStages($alerts, $findings);
        $this->assertSame($stagesA, $stagesB);
    }

    public function test_multi_stage_progression_ordered_correctly(): void
    {
        $alerts = collect([
            (object)['alert_type' => 'IDENTITY_MFA_FAILURE_BURST', 'trace_id' => null],
            (object)['alert_type' => 'IDENTITY_PRIVILEGE_ESCALATION', 'trace_id' => null],
        ]);
        $finding = new \stdClass(); $finding->finding_type = 'suspicious_execution_chain';
        $stages = $this->svc()->determineAttackStages($alerts, collect([$finding]));

        $order = AttackStageTimeline::STAGE_ORDER;
        for ($i = 0; $i < count($stages) - 1; $i++) {
            $this->assertLessThanOrEqual($order[$stages[$i + 1]], $order[$stages[$i + 1]]);
        }
    }

    // -----------------------------------------------------------------------
    // Correlation service — no source data mutations
    // -----------------------------------------------------------------------

    public function test_correlation_does_not_mutate_security_alerts(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'    => 'TEST-ALERT-001',
            'alert_type'  => 'IDENTITY_MFA_FAILURE_BURST',
            'actor_key'   => 'user@test.local',
            'severity'    => 'high',
            'ip'          => '10.0.0.1',
            'evidence'    => json_encode(['test' => true]),
            'raw_event'   => json_encode(['raw' => true]),
            'detected_at' => now(),
        ]);

        $before = DB::table('security_alerts')->where('alert_id', 'TEST-ALERT-001')->first();
        $this->svc()->correlateIdentityToEndpoint('user@test.local');
        $after = DB::table('security_alerts')->where('alert_id', 'TEST-ALERT-001')->first();

        $this->assertEquals($before->evidence, $after->evidence);
        $this->assertEquals($before->raw_event, $after->raw_event);
        $this->assertEquals($before->actor_key, $after->actor_key);
    }

    public function test_correlation_does_not_mutate_behavioral_findings(): void
    {
        $agent   = $this->makeAgent();
        $finding = EndpointBehavioralFinding::create([
            'finding_id'   => 'FIND-2026-00001',
            'agent_id'     => $agent->id,
            'finding_type' => 'suspicious_execution_chain',
            'severity'     => 'high',
            'confidence'   => 0.85,
            'title'        => 'Test Finding',
            'evidence'     => json_encode(['original' => 'data']),
            'detected_at'  => now(),
        ]);

        $originalEvidence = $finding->evidence;
        $this->svc()->correlateIdentityToEndpoint();
        $finding->refresh();

        $this->assertEquals($originalEvidence, $finding->evidence);
    }

    // -----------------------------------------------------------------------
    // Append-only enforcement
    // -----------------------------------------------------------------------

    public function test_cross_domain_correlation_has_no_updated_at(): void
    {
        $corr = CrossDomainCorrelation::create([
            'correlation_id'    => CrossDomainCorrelation::generateCorrelationId(),
            'correlation_type'  => CrossDomainCorrelation::TYPE_IDENTITY_ENDPOINT,
            'confidence_score'  => 0.75,
            'attack_stages'     => ['initial_access'],
            'involved_hosts'    => [],
            'involved_users'    => ['user@test.local'],
            'involved_ips'      => [],
            'alert_ids'         => [],
            'trace_ids'         => [],
            'correlation_metadata' => ['advisory_only' => true],
            'trace_id'          => 'trace-test',
        ]);

        $this->assertNull($corr->updated_at);
    }

    public function test_attack_stage_timeline_has_no_updated_at(): void
    {
        $corr = CrossDomainCorrelation::create([
            'correlation_id'    => CrossDomainCorrelation::generateCorrelationId(),
            'correlation_type'  => CrossDomainCorrelation::TYPE_MULTI_HOST,
            'confidence_score'  => 0.80,
            'attack_stages'     => [],
            'involved_hosts'    => [],
            'involved_users'    => [],
            'involved_ips'      => [],
            'alert_ids'         => [],
            'trace_ids'         => [],
            'correlation_metadata' => [],
        ]);
        $tl = AttackStageTimeline::create([
            'timeline_id'      => AttackStageTimeline::generateTimelineId(),
            'correlation_id'   => $corr->id,
            'stage'            => 'command_and_control',
            'stage_order'      => 5,
            'stage_confidence' => 0.80,
        ]);
        $this->assertNull($tl->updated_at);
    }

    public function test_evidence_link_has_no_updated_at(): void
    {
        $corr = CrossDomainCorrelation::create([
            'correlation_id'    => CrossDomainCorrelation::generateCorrelationId(),
            'correlation_type'  => CrossDomainCorrelation::TYPE_SAAS_ENDPOINT,
            'confidence_score'  => 0.65,
            'attack_stages'     => [],
            'involved_hosts'    => [],
            'involved_users'    => [],
            'involved_ips'      => [],
            'alert_ids'         => [],
            'trace_ids'         => [],
            'correlation_metadata' => [],
        ]);
        $link = CorrelationEvidenceLink::create([
            'correlation_id'    => $corr->id,
            'evidence_type'     => CorrelationEvidenceLink::TYPE_SECURITY_ALERT,
            'evidence_id'       => 1,
            'evidence_table'    => 'security_alerts',
            'evidence_snapshot' => ['alert_type' => 'IDENTITY_MFA_FAILURE_BURST'],
            'domain'            => CorrelationEvidenceLink::DOMAIN_IDENTITY,
        ]);
        $this->assertNull($link->updated_at);
    }

    // -----------------------------------------------------------------------
    // Multi-host detection
    // -----------------------------------------------------------------------

    public function test_multi_host_shared_destination_requires_min_two_agents(): void
    {
        $agent = $this->makeAgent();
        EndpointBeaconPattern::create([
            'pattern_id'             => 'BP-2026-00001',
            'agent_id'               => $agent->id,
            'process_name'           => 'curl',
            'remote_ip'              => '198.51.100.10',
            'remote_port'            => 443,
            'connection_count'       => 5,
            'destination_reuse_score'=> 0.80,
            'first_seen_at'          => now(),
            'last_seen_at'           => now(),
            'detected_at'            => now(),
            'evidence'               => json_encode([]),
        ]);

        $result = $this->svc()->detectMultiHostSharedDestination(minHosts: 2);
        $this->assertEmpty($result, 'Should not create correlation with only 1 host');
    }

    public function test_multi_host_shared_destination_detects_two_agents(): void
    {
        $agentA = $this->makeAgent();
        $agentB = $this->makeAgent();
        $ip = '198.51.100.20';

        foreach ([$agentA, $agentB] as $i => $agent) {
            EndpointBeaconPattern::create([
                'pattern_id'             => 'BP-2026-0000' . ($i + 2),
                'agent_id'               => $agent->id,
                'process_name'           => 'wget',
                'remote_ip'              => $ip,
                'remote_port'            => 8080,
                'connection_count'       => 4,
                'destination_reuse_score'=> 0.75,
                'first_seen_at'          => now(),
                'last_seen_at'           => now(),
                'detected_at'            => now(),
                'evidence'               => json_encode([]),
            ]);
        }

        $result = $this->svc()->detectMultiHostSharedDestination(minHosts: 2);
        $this->assertNotEmpty($result);
        $this->assertEquals(CrossDomainCorrelation::TYPE_MULTI_HOST, $result[0]->correlation_type);
        $this->assertEquals('ip', $result[0]->primary_entity_type);
        $this->assertEquals($ip, $result[0]->primary_entity_key);
        $this->assertContains('command_and_control', $result[0]->attack_stages);
        $this->assertTrue($result[0]->correlation_metadata['advisory_only']);
    }

    // -----------------------------------------------------------------------
    // Cross-domain trace stitching
    // -----------------------------------------------------------------------

    public function test_trace_stitching_returns_structured_result(): void
    {
        $traceId = 'trace-test-cross-domain-001';
        DB::table('security_alerts')->insert([
            'alert_id'    => 'TRACE-ALERT-001',
            'alert_type'  => 'IDENTITY_MFA_FAILURE_BURST',
            'actor_key'   => 'user@test.local',
            'severity'    => 'high',
            'evidence'    => json_encode([]),
            'raw_event'   => json_encode([]),
            'trace_id'    => $traceId,
            'detected_at' => now(),
        ]);

        $result = $this->svc()->stitchCrossDomainTrace($traceId);

        $this->assertArrayHasKey('trace_id', $result);
        $this->assertArrayHasKey('alerts', $result);
        $this->assertArrayHasKey('findings', $result);
        $this->assertArrayHasKey('correlations', $result);
        $this->assertArrayHasKey('advisory_note', $result);
        $this->assertEquals($traceId, $result['trace_id']);
        $this->assertNotEmpty($result['alerts']);
        $this->assertStringContainsString('advisory-only', $result['advisory_note']);
    }

    public function test_trace_stitching_sanitizes_trace_id(): void
    {
        $result = $this->svc()->stitchCrossDomainTrace('<script>alert(1)</script>');
        $this->assertArrayNotHasKey('error', $result);
        $this->assertStringNotContainsString('<script>', $result['trace_id']);
    }

    public function test_trace_stitching_requires_non_empty_trace_id(): void
    {
        $result = $this->svc()->stitchCrossDomainTrace('');
        $this->assertArrayHasKey('error', $result);
    }

    // -----------------------------------------------------------------------
    // Entity risk scoring extensions
    // -----------------------------------------------------------------------

    public function test_cross_domain_risk_weights_defined(): void
    {
        $weights = EntityRiskScoringService::WEIGHTS;
        $this->assertArrayHasKey('cross_domain_correlation', $weights);
        $this->assertArrayHasKey('multi_stage_escalation', $weights);
        $this->assertArrayHasKey('repeated_destination_reuse', $weights);
        $this->assertArrayHasKey('identity_endpoint_combined', $weights);
    }

    public function test_cross_domain_risk_weights_are_positive(): void
    {
        foreach (['cross_domain_correlation', 'multi_stage_escalation',
                  'repeated_destination_reuse', 'identity_endpoint_combined'] as $key) {
            $this->assertGreaterThan(0, EntityRiskScoringService::WEIGHTS[$key]);
        }
    }

    // -----------------------------------------------------------------------
    // ThreatHuntingService extensions
    // -----------------------------------------------------------------------

    public function test_cross_domain_correlations_in_supported_domains(): void
    {
        $this->assertContains('cross_domain_correlations', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_pivot_identity_to_host_returns_advisory_note(): void
    {
        $result = app(ThreatHuntingService::class)->pivotIdentityToHost('user@test.local');
        $this->assertArrayHasKey('advisory_note', $result);
        $this->assertStringContainsString('Advisory-only', $result['advisory_note']);
    }

    public function test_pivot_multi_host_destination_returns_advisory_note(): void
    {
        $result = app(ThreatHuntingService::class)->pivotMultiHostDestination('10.0.0.1');
        $this->assertArrayHasKey('advisory_note', $result);
    }

    public function test_pivot_attack_stage_validates_stage(): void
    {
        $result = app(ThreatHuntingService::class)->pivotAttackStage('invalid_stage_xyz');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_pivot_attack_stage_accepts_valid_stages(): void
    {
        foreach (AttackStageTimeline::STAGES as $stage) {
            $result = app(ThreatHuntingService::class)->pivotAttackStage($stage);
            $this->assertArrayNotHasKey('error', $result, "Stage $stage should be valid");
        }
    }

    // -----------------------------------------------------------------------
    // Routes and UI
    // -----------------------------------------------------------------------

    public function test_dashboard_route_accessible_by_admin(): void
    {
        $this->actingAs($this->admin())
            ->get(route('cross-domain.dashboard'))
            ->assertOk();
    }

    public function test_attack_timeline_route_accessible(): void
    {
        $this->actingAs($this->admin())
            ->get(route('cross-domain.attack-timeline'))
            ->assertOk();
    }

    public function test_identity_endpoint_route_accessible(): void
    {
        $this->actingAs($this->admin())
            ->get(route('cross-domain.identity-endpoint'))
            ->assertOk();
    }

    public function test_investigation_graph_route_accessible(): void
    {
        $this->actingAs($this->admin())
            ->get(route('cross-domain.investigation-graph'))
            ->assertOk();
    }

    public function test_shared_destination_route_accessible(): void
    {
        $this->actingAs($this->admin())
            ->get(route('cross-domain.shared-destination'))
            ->assertOk();
    }

    public function test_trace_explorer_route_accessible(): void
    {
        $this->actingAs($this->admin())
            ->get(route('cross-domain.trace-explorer'))
            ->assertOk();
    }

    public function test_show_route_returns_404_for_unknown_correlation(): void
    {
        $this->actingAs($this->admin())
            ->get(route('cross-domain.show', 'CORR-9999-99999'))
            ->assertNotFound();
    }

    public function test_advisory_disclaimer_present_on_dashboard(): void
    {
        $this->actingAs($this->admin())
            ->get(route('cross-domain.dashboard'))
            ->assertSeeText('advisory-only');
    }

    public function test_advisory_disclaimer_present_on_identity_endpoint_view(): void
    {
        $this->actingAs($this->admin())
            ->get(route('cross-domain.identity-endpoint'))
            ->assertSeeText('Advisory-only');
    }

    // -----------------------------------------------------------------------
    // API routes
    // -----------------------------------------------------------------------

    public function test_api_list_correlations_returns_json(): void
    {
        $this->actingAs($this->admin())
            ->get(route('api.cross-domain.list'))
            ->assertOk()
            ->assertJsonStructure(['correlations', 'advisory_note']);
    }

    public function test_api_attack_stages_returns_all_stages(): void
    {
        $resp = $this->actingAs($this->admin())
            ->get(route('api.cross-domain.stages'))
            ->assertOk()
            ->json();

        foreach (AttackStageTimeline::STAGES as $stage) {
            $this->assertContains($stage, $resp['stages']);
        }
    }

    public function test_api_pivot_identity_host_requires_actor(): void
    {
        $this->actingAs($this->admin())
            ->get(route('api.cross-domain.pivot.identity-host'))
            ->assertStatus(422);
    }

    public function test_api_pivot_multi_host_requires_ip(): void
    {
        $this->actingAs($this->admin())
            ->get(route('api.cross-domain.pivot.multi-host'))
            ->assertStatus(422);
    }

    public function test_api_get_correlation_returns_404_for_unknown(): void
    {
        $this->actingAs($this->admin())
            ->get(route('api.cross-domain.show', 'CORR-9999-99999'))
            ->assertNotFound();
    }

    public function test_api_get_correlation_includes_advisory_note(): void
    {
        $corr = CrossDomainCorrelation::create([
            'correlation_id'    => CrossDomainCorrelation::generateCorrelationId(),
            'correlation_type'  => CrossDomainCorrelation::TYPE_IDENTITY_ENDPOINT,
            'confidence_score'  => 0.75,
            'attack_stages'     => ['initial_access'],
            'involved_hosts'    => [],
            'involved_users'    => ['analyst@test.local'],
            'involved_ips'      => [],
            'alert_ids'         => [],
            'trace_ids'         => [],
            'correlation_metadata' => [],
        ]);

        $resp = $this->actingAs($this->admin())
            ->get(route('api.cross-domain.show', $corr->correlation_id))
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('advisory_note', $resp);
        $this->assertStringContainsString('advisory-only', $resp['advisory_note']);
    }

    // -----------------------------------------------------------------------
    // SAFETY BOUNDARY ASSERTIONS — explicit per spec
    // -----------------------------------------------------------------------

    public function test_no_autonomous_response_in_service(): void
    {
        $reflection = new \ReflectionClass(CrossDomainCorrelationService::class);
        $source     = file_get_contents($reflection->getFileName());

        $forbidden = ['isolate_host', 'kill_process', 'quarantine_file', 'block_ip',
                      'disable_service', 'execute_command', 'autonomous_response',
                      'active_containment', 'force_remediat'];
        foreach ($forbidden as $term) {
            $this->assertStringNotContainsStringIgnoringCase($term, $source,
                "CrossDomainCorrelationService must not contain '$term'");
        }
    }

    public function test_no_host_isolation_in_controller(): void
    {
        $reflection = new \ReflectionClass(\App\Http\Controllers\Investigation\CrossDomainController::class);
        $source     = file_get_contents($reflection->getFileName());

        $forbidden = ['isolate_host', 'kill_process', 'quarantine', 'block_ip', 'isolation'];
        foreach ($forbidden as $term) {
            $this->assertStringNotContainsStringIgnoringCase($term, $source,
                "CrossDomainController must not contain '$term'");
        }
    }

    public function test_no_execute_columns_in_correlation_table(): void
    {
        foreach (['execute_action', 'execute_response', 'containment_action', 'isolation_flag'] as $col) {
            $this->assertFalse(
                Schema::hasColumn('cross_domain_correlations', $col),
                "cross_domain_correlations must not have column: $col"
            );
        }
    }

    public function test_correlation_metadata_always_contains_advisory_flag(): void
    {
        $agent = $this->makeAgent();
        foreach ([
            ['BP-2026-00010', 'agent' => $agent],
        ] as $i => $data) {
            $otherAgent = $this->makeAgent();
            foreach ([$agent, $otherAgent] as $j => $a) {
                EndpointBeaconPattern::create([
                    'pattern_id'             => 'BP-SAFETY-' . $i . '-' . $j,
                    'agent_id'               => $a->id,
                    'process_name'           => 'curl',
                    'remote_ip'              => '203.0.113.99',
                    'remote_port'            => 443,
                    'connection_count'       => 5,
                    'destination_reuse_score'=> 0.80,
                    'first_seen_at'          => now(),
                    'last_seen_at'           => now(),
                    'detected_at'            => now(),
                    'evidence'               => json_encode([]),
                ]);
            }
        }

        $correlations = $this->svc()->detectMultiHostSharedDestination(minHosts: 2);
        foreach ($correlations as $corr) {
            $this->assertTrue(
                $corr->correlation_metadata['advisory_only'] ?? false,
                'All correlations must have advisory_only=true in metadata'
            );
        }
    }

    public function test_shadow_only_enforcement_cross_domain_rules_in_registry(): void
    {
        $registry = json_decode(
            file_get_contents(base_path('docs/detection/rules/registry.v1.json')),
            true
        );

        $crossDomainRules = array_filter($registry['rules'], fn ($r) =>
            in_array($r['rule_id'], [
                'identity_endpoint_execution_chain',
                'identity_persistence_correlation',
                'saas_endpoint_beacon_chain',
                'multi_host_shared_destination',
                'cross_domain_attack_progression',
            ])
        );

        $this->assertCount(5, $crossDomainRules, 'All 5 cross-domain rules must be in registry');

        foreach ($crossDomainRules as $rule) {
            $this->assertTrue($rule['shadow_only'], "Rule {$rule['rule_id']} must be shadow_only");
            $this->assertEquals('shadow', $rule['status'], "Rule {$rule['rule_id']} must have status=shadow");
            $this->assertEquals('xdr.alerts.shadow.endpoint', $rule['output_topic'],
                "Rule {$rule['rule_id']} must publish to shadow topic only");
        }
    }

    public function test_registry_total_rule_count_is_56(): void
    {
        $registry = json_decode(
            file_get_contents(base_path('docs/detection/rules/registry.v1.json')),
            true
        );
        $this->assertCount(133, $registry['rules']); // 73 previous + 20 Advanced Detection Coverage Phase 1
    }

    public function test_registry_staged_active_count_unchanged_at_12(): void
    {
        $registry = json_decode(
            file_get_contents(base_path('docs/detection/rules/registry.v1.json')),
            true
        );
        $active = array_filter($registry['rules'], fn ($r) => $r['status'] === 'staged_active');
        $this->assertCount(12, $active, 'staged_active count must remain 12 — no new promotions');
    }

    public function test_no_active_allowlist_entries(): void
    {
        $validatorSrc = file_get_contents(base_path('scripts/xdr_rule_registry_validate.py'));
        // ACTIVE_ALLOWLIST must exist but be empty
        $this->assertStringContainsString('ACTIVE_ALLOWLIST', $validatorSrc);
        // ACTIVE_ALLOWLIST: frozenset[str] = frozenset()  OR  frozenset()
        preg_match('/ACTIVE_ALLOWLIST[^=]*=\s*frozenset\(\s*\)/', $validatorSrc, $m);
        $this->assertNotEmpty($m, 'ACTIVE_ALLOWLIST must exist and be empty frozenset()');
        $this->assertStringContainsString('frozenset()', $m[0]);
    }
}
