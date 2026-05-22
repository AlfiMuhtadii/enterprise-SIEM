<?php

namespace Tests\Feature;

use App\Models\AdversarialValidationRun;
use App\Models\AttackChainTimeline;
use App\Models\AttackScenarioPack;
use App\Models\ChainedDetectionGraph;
use App\Models\CrossHostCorrelationRun;
use App\Models\DetectionConfidenceReport;
use App\Models\EvasionResilienceReport;
use App\Models\ReplayAttackFixture;
use App\Models\TacticProgressionSnapshot;
use App\Services\AdvancedDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvancedDetectionCoverageTest extends TestCase
{
    use RefreshDatabase;

    private AdvancedDetectionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(AdvancedDetectionService::class);
    }

    // ─── Hard constraints ─────────────────────────────────────────────────────

    public function test_no_isolate_host(): void
    {
        $this->assertFalse(method_exists($this->svc, 'isolateHost'));
    }

    public function test_no_quarantine_host(): void
    {
        $this->assertFalse(method_exists($this->svc, 'quarantineHost'));
    }

    public function test_no_execute_shell(): void
    {
        $this->assertFalse(method_exists($this->svc, 'executeShell'));
    }

    public function test_no_kill_process(): void
    {
        $this->assertFalse(method_exists($this->svc, 'killProcess'));
    }

    public function test_no_auto_remediate(): void
    {
        $this->assertFalse(method_exists($this->svc, 'autoRemediate'));
    }

    public function test_no_offensive_payload_execution(): void
    {
        $this->assertFalse(method_exists($this->svc, 'executePayload'));
        $this->assertFalse(method_exists($this->svc, 'deployExploit'));
    }

    public function test_no_destructive_attack_simulation(): void
    {
        $this->assertFalse(method_exists($this->svc, 'simulateLiveAttack'));
        $this->assertFalse(method_exists($this->svc, 'executeAttack'));
    }

    public function test_no_hidden_ai_decision_engine(): void
    {
        $this->assertFalse(method_exists($this->svc, 'runNeuralDecision'));
        $this->assertFalse(method_exists($this->svc, 'mlEnforce'));
    }

    public function test_no_automatic_enforcement(): void
    {
        $this->assertFalse(method_exists($this->svc, 'autoEnforce'));
        $this->assertFalse(method_exists($this->svc, 'automaticCountermeasure'));
    }

    public function test_advisory_only_flag_in_dashboard_stats(): void
    {
        $stats = $this->svc->getDashboardStats();
        $this->assertTrue($stats['advisory_only']);
    }

    // ─── Adversarial Validation ───────────────────────────────────────────────

    public function test_adversarial_validation_pass_verdict(): void
    {
        $run = $this->svc->runAdversarialValidation(
            scenarioName: 'credential_dump_scenario',
            triggeredBy: 'analyst-x',
            attackTactic: 'credential_access',
            attackTechnique: 'T1003',
            detected: true,
            falsePositiveFree: true,
            matchedRuleIds: ['CRED_LSASS_ACCESS_INDICATOR', 'CRED_DUMP_INDICATOR'],
        );

        $this->assertEquals(AdversarialValidationRun::VERDICT_PASS, $run->verdict);
        $this->assertTrue($run->detected);
        $this->assertTrue($run->false_positive_free);
        $this->assertEquals(2, $run->matched_rules);
        $this->assertGreaterThan(0.0, $run->detection_confidence);
    }

    public function test_adversarial_validation_fail_verdict(): void
    {
        $run = $this->svc->runAdversarialValidation(
            scenarioName: 'missed_scenario',
            triggeredBy: 'analyst-y',
            detected: false,
        );
        $this->assertEquals(AdversarialValidationRun::VERDICT_FAIL, $run->verdict);
        $this->assertEquals(0.0, $run->detection_confidence);
    }

    public function test_adversarial_validation_partial_verdict(): void
    {
        $run = $this->svc->runAdversarialValidation(
            scenarioName: 'fp_scenario',
            triggeredBy: 'analyst-z',
            detected: true,
            falsePositiveFree: false,
        );
        $this->assertEquals(AdversarialValidationRun::VERDICT_PARTIAL, $run->verdict);
    }

    public function test_adversarial_validation_is_append_only(): void
    {
        $run = $this->svc->runAdversarialValidation('s', 'eng', detected: true);
        $this->expectException(\LogicException::class);
        $run->verdict = 'pass';
        $run->save();
    }

    public function test_adversarial_validation_run_id_prefix(): void
    {
        $run = $this->svc->runAdversarialValidation('s', 'eng');
        $this->assertStringStartsWith('avr-', $run->run_id);
    }

    public function test_adversarial_validation_deterministic_confidence(): void
    {
        $r1 = $this->svc->runAdversarialValidation('s', 'eng', detected: true, falsePositiveFree: true, matchedRuleIds: ['R1', 'R2']);
        $r2 = $this->svc->runAdversarialValidation('s', 'eng', detected: true, falsePositiveFree: true, matchedRuleIds: ['R1', 'R2']);
        $this->assertEquals($r1->detection_confidence, $r2->detection_confidence);
    }

    // ─── Chained Detection ────────────────────────────────────────────────────

    public function test_chained_detection_persists(): void
    {
        $graph = $this->svc->recordChainedDetection(
            chainType: 'lateral_movement',
            nodeSequence: ['proc-A', 'proc-B', 'proc-C'],
            tacticSequence: ['execution', 'lateral_movement'],
            triggeredBy: 'analyst-a',
            hostId: 'host-001',
        );

        $this->assertDatabaseHas('chained_detection_graphs', [
            'chain_type' => 'lateral_movement',
            'hop_count'  => 3,
            'host_id'    => 'host-001',
        ]);
        $this->assertGreaterThan(0.0, $graph->chain_confidence);
    }

    public function test_chained_detection_rejects_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->recordChainedDetection('unknown_chain', [], [], 'eng');
    }

    public function test_chained_detection_bounded_max_hops(): void
    {
        $manyNodes = range(1, 25);
        $graph = $this->svc->recordChainedDetection(
            'lateral_movement', array_map(fn($n) => "node-{$n}", $manyNodes), [], 'eng'
        );
        $this->assertLessThanOrEqual(AdvancedDetectionService::MAX_CHAIN_HOPS, $graph->hop_count);
    }

    public function test_chained_detection_is_append_only(): void
    {
        $g = $this->svc->recordChainedDetection('lateral_movement', ['n1'], ['execution'], 'eng');
        $this->expectException(\LogicException::class);
        $g->status = 'closed';
        $g->save();
    }

    public function test_chain_confidence_is_deterministic(): void
    {
        $g1 = $this->svc->recordChainedDetection('lateral_movement', ['n1','n2'], ['execution','lateral_movement'], 'eng');
        $g2 = $this->svc->recordChainedDetection('lateral_movement', ['n1','n2'], ['execution','lateral_movement'], 'eng');
        $this->assertEquals($g1->chain_confidence, $g2->chain_confidence);
    }

    // ─── Attack Chain Timeline ────────────────────────────────────────────────

    public function test_append_chain_timeline_persists(): void
    {
        $entry = $this->svc->appendChainTimeline(
            tactic: 'credential_access',
            eventType: 'process_access',
            hostId: 'host-42',
            techniqueId: 'T1003',
            sequenceIndex: 1,
        );

        $this->assertDatabaseHas('attack_chain_timelines', [
            'tactic'    => 'credential_access',
            'host_id'   => 'host-42',
            'technique_id' => 'T1003',
        ]);
        $this->assertStringStartsWith('act-', $entry->timeline_id);
    }

    public function test_attack_chain_timeline_is_append_only(): void
    {
        $e = $this->svc->appendChainTimeline('execution', 'process_exec');
        $this->expectException(\LogicException::class);
        $e->tactic = 'impact';
        $e->save();
    }

    // ─── Evasion Resilience ───────────────────────────────────────────────────

    public function test_evasion_resilience_detection_survived(): void
    {
        $report = $this->svc->reportEvasionResilience(
            evasionType: 'telemetry_gap',
            testedBy: 'eng',
            detectionSurvived: true,
            confidenceDegradation: 0.15,
        );

        $this->assertTrue($report->detection_survived);
        $this->assertEqualsWithDelta(0.85, $report->resilience_score, 0.001);
    }

    public function test_evasion_resilience_detection_failed(): void
    {
        $report = $this->svc->reportEvasionResilience(
            evasionType: 'encoded_command',
            testedBy: 'eng',
            detectionSurvived: false,
            confidenceDegradation: 0.9,
        );
        $this->assertFalse($report->detection_survived);
        $this->assertEqualsWithDelta(0.1, $report->resilience_score, 0.001);
    }

    public function test_evasion_resilience_rejects_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->reportEvasionResilience('unknown_evasion', 'eng');
    }

    public function test_all_evasion_types_accepted(): void
    {
        foreach (AdvancedDetectionService::EVASION_TYPES as $type) {
            $r = $this->svc->reportEvasionResilience($type, 'eng', detectionSurvived: true);
            $this->assertEquals($type, $r->evasion_type);
        }
    }

    public function test_evasion_resilience_is_append_only(): void
    {
        $r = $this->svc->reportEvasionResilience('telemetry_gap', 'eng');
        $this->expectException(\LogicException::class);
        $r->detection_survived = true;
        $r->save();
    }

    public function test_resilience_score_bounded_zero_to_one(): void
    {
        $r = $this->svc->reportEvasionResilience('telemetry_gap', 'eng', confidenceDegradation: 1.5);
        $this->assertGreaterThanOrEqual(0.0, $r->resilience_score);
        $this->assertLessThanOrEqual(1.0, $r->resilience_score);
    }

    // ─── Detection Confidence ─────────────────────────────────────────────────

    public function test_confidence_report_fp_rate_computed(): void
    {
        $report = $this->svc->recordConfidenceReport(
            ruleId: 'CRED_LSASS_ACCESS_INDICATOR',
            confidenceScore: 0.80,
            evaluatedBy: 'eng',
            truePositives: 8,
            falsePositives: 2,
            sampleSize: 10,
        );

        $this->assertEqualsWithDelta(0.2, $report->fp_rate, 0.001);
        $this->assertStringStartsWith('dcr-', $report->report_id);
    }

    public function test_confidence_report_zero_fp_rate_on_no_samples(): void
    {
        $r = $this->svc->recordConfidenceReport('RULE_X', 0.5, 'eng');
        $this->assertEquals(0.0, $r->fp_rate);
    }

    public function test_confidence_report_is_append_only(): void
    {
        $r = $this->svc->recordConfidenceReport('R1', 0.7, 'eng');
        $this->expectException(\LogicException::class);
        $r->confidence_score = 1.0;
        $r->save();
    }

    // ─── Tactic Progression ───────────────────────────────────────────────────

    public function test_tactic_progression_multi_stage_on_3_plus_tactics(): void
    {
        $snap = $this->svc->snapshotTacticProgression(
            observedTactics: ['execution', 'persistence', 'lateral_movement'],
            observedTechniques: ['T1059', 'T1547', 'T1021'],
            hostId: 'host-99',
        );

        $this->assertTrue($snap->multi_stage);
        $this->assertEquals(3, $snap->tactic_count);
        $this->assertGreaterThan(0.0, $snap->progression_score);
    }

    public function test_tactic_progression_not_multi_stage_on_two_tactics(): void
    {
        $snap = $this->svc->snapshotTacticProgression(
            observedTactics: ['execution', 'persistence'],
            observedTechniques: [],
        );
        $this->assertFalse($snap->multi_stage);
    }

    public function test_tactic_progression_is_append_only(): void
    {
        $s = $this->svc->snapshotTacticProgression(['execution'], []);
        $this->expectException(\LogicException::class);
        $s->multi_stage = true;
        $s->save();
    }

    public function test_progression_score_is_deterministic(): void
    {
        $tactics = ['credential_access', 'lateral_movement', 'impact'];
        $s1 = $this->svc->snapshotTacticProgression($tactics, []);
        $s2 = $this->svc->snapshotTacticProgression($tactics, []);
        $this->assertEquals($s1->progression_score, $s2->progression_score);
    }

    // ─── Cross-Host Correlation ───────────────────────────────────────────────

    public function test_cross_host_correlation_persists(): void
    {
        $run = $this->svc->runCrossHostCorrelation(
            hostIds: ['host-A', 'host-B', 'host-C'],
            correlationType: 'lateral_movement',
            triggeredBy: 'analyst',
            sharedIndicators: ['proc-x', 'dest-ip-1'],
            propagationDetected: true,
        );

        $this->assertTrue($run->propagation_detected);
        $this->assertEquals(3, $run->host_count);
        $this->assertGreaterThan(0.0, $run->correlation_confidence);
        $this->assertStringStartsWith('chr-', $run->run_id);
    }

    public function test_cross_host_correlation_bounded(): void
    {
        $manyHosts = array_map(fn($n) => "host-{$n}", range(1, 30));
        $run = $this->svc->runCrossHostCorrelation($manyHosts, 'lateral_movement', 'eng');
        $this->assertLessThanOrEqual(AdvancedDetectionService::MAX_CORRELATION_HOSTS, $run->host_count);
    }

    public function test_cross_host_rejects_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->runCrossHostCorrelation(['host-A'], 'unknown_type', 'eng');
    }

    public function test_cross_host_is_append_only(): void
    {
        $r = $this->svc->runCrossHostCorrelation(['h1'], 'lateral_movement', 'eng');
        $this->expectException(\LogicException::class);
        $r->propagation_detected = true;
        $r->save();
    }

    public function test_correlation_confidence_deterministic(): void
    {
        $args = [['h1','h2','h3'], 'lateral_movement', 'eng', ['ind-1','ind-2']];
        $r1 = $this->svc->runCrossHostCorrelation(...$args);
        $r2 = $this->svc->runCrossHostCorrelation(...$args);
        $this->assertEquals($r1->correlation_confidence, $r2->correlation_confidence);
    }

    // ─── Scenario Packs & Fixtures ────────────────────────────────────────────

    public function test_create_scenario_pack_persists(): void
    {
        $pack = $this->svc->createScenarioPack(
            name: 'Credential Dump Pack',
            attackTactic: 'credential_access',
            techniqueIds: ['T1003', 'T1003.001'],
        );
        $this->assertDatabaseHas('attack_scenario_packs', [
            'name'          => 'Credential Dump Pack',
            'attack_tactic' => 'credential_access',
            'is_active'     => true,
        ]);
        $this->assertStringStartsWith('asp-', $pack->pack_id);
    }

    public function test_create_replay_fixture_persists(): void
    {
        $fix = $this->svc->createReplayFixture(
            name: 'LSASS Access Fixture',
            attackTactic: 'credential_access',
            eventSequence: [['event_type' => 'process_access', 'target' => 'lsass.exe']],
            fixtureType: 'malicious',
        );
        $this->assertDatabaseHas('replay_attack_fixtures', [
            'name'         => 'LSASS Access Fixture',
            'fixture_type' => 'malicious',
            'is_active'    => true,
        ]);
        $this->assertStringStartsWith('raf-', $fix->fixture_id);
    }

    // ─── Hunt domain integration ──────────────────────────────────────────────

    public function test_adversarial_validation_runs_domain_supported(): void
    {
        $hunting = app(\App\Services\ThreatHuntingService::class);
        $this->assertContains('adversarial_validation_runs', $hunting->supportedDomains());
    }

    public function test_attack_chain_timelines_domain_supported(): void
    {
        $hunting = app(\App\Services\ThreatHuntingService::class);
        $this->assertContains('attack_chain_timelines', $hunting->supportedDomains());
    }

    public function test_chained_detection_graphs_domain_supported(): void
    {
        $hunting = app(\App\Services\ThreatHuntingService::class);
        $this->assertContains('chained_detection_graphs', $hunting->supportedDomains());
    }

    public function test_evasion_resilience_reports_domain_supported(): void
    {
        $hunting = app(\App\Services\ThreatHuntingService::class);
        $this->assertContains('evasion_resilience_reports', $hunting->supportedDomains());
    }

    public function test_cross_host_correlation_runs_domain_supported(): void
    {
        $hunting = app(\App\Services\ThreatHuntingService::class);
        $this->assertContains('cross_host_correlation_runs', $hunting->supportedDomains());
    }

    public function test_total_hunt_domains(): void
    {
        $hunting = app(\App\Services\ThreatHuntingService::class);
        $this->assertCount(85, $hunting->supportedDomains());
    }

    // ─── Routes ───────────────────────────────────────────────────────────────

    public function test_attack_coverage_dashboard_route(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'detection_engineer']);
        $this->actingAs($user)->get(route('advanced-detection.dashboard'))->assertStatus(200);
    }

    public function test_attack_chain_explorer_route(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'detection_engineer']);
        $this->actingAs($user)->get(route('advanced-detection.chains'))->assertStatus(200);
    }

    public function test_adversarial_replay_console_route(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'detection_engineer']);
        $this->actingAs($user)->get(route('advanced-detection.adversarial'))->assertStatus(200);
    }

    public function test_evasion_resilience_viewer_route(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'detection_engineer']);
        $this->actingAs($user)->get(route('advanced-detection.evasion'))->assertStatus(200);
    }

    public function test_advisory_notice_on_views(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'detection_engineer']);
        $this->actingAs($user)->get(route('advanced-detection.dashboard'))
            ->assertSee('advisory-only');
    }

    // ─── Dashboard stats ──────────────────────────────────────────────────────

    public function test_dashboard_stats_all_keys_present(): void
    {
        $stats = $this->svc->getDashboardStats();
        $keys = [
            'total_validation_runs', 'pass_runs', 'fail_runs', 'total_scenario_packs',
            'active_scenario_packs', 'total_chained_graphs', 'total_evasion_reports',
            'detection_survived_count', 'total_chain_timelines', 'multi_stage_progressions',
            'total_confidence_reports', 'total_cross_host_runs', 'propagation_detected',
            'total_replay_fixtures', 'active_replay_fixtures', 'advisory_only',
        ];
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $stats);
        }
    }
}
