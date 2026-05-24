<?php

namespace Tests\Feature;

use App\Models\SyntheticAttackFixture;
use App\Models\DetectionQualityScorecard;
use App\Models\FalsePositiveNegativeReport;
use App\Models\TelemetryQualityScorecard;
use App\Models\AnalystTriageSimulationRun;
use App\Models\PerformanceHotspotReport;
use App\Models\NoisyEnterpriseSimulation;
use App\Models\XdrMaturityScorecard;
use App\Models\XdrReadinessReportArtifact;
use App\Services\CodeLevelXdrMaturityService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CodeLevelXdrMaturityTest extends TestCase
{
    use RefreshDatabase;

    private CodeLevelXdrMaturityService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new CodeLevelXdrMaturityService();
    }

    // -----------------------------------------------------------------------
    // Table existence
    // -----------------------------------------------------------------------

    public function test_synthetic_attack_fixtures_table_exists(): void
    {
        $this->assertTrue(\Schema::hasTable('synthetic_attack_fixtures'));
    }

    public function test_detection_quality_scorecards_table_exists(): void
    {
        $this->assertTrue(\Schema::hasTable('detection_quality_scorecards'));
    }

    public function test_false_positive_negative_reports_table_exists(): void
    {
        $this->assertTrue(\Schema::hasTable('false_positive_negative_reports'));
    }

    public function test_telemetry_quality_scorecards_table_exists(): void
    {
        $this->assertTrue(\Schema::hasTable('telemetry_quality_scorecards'));
    }

    public function test_analyst_triage_simulation_runs_table_exists(): void
    {
        $this->assertTrue(\Schema::hasTable('analyst_triage_simulation_runs'));
    }

    public function test_performance_hotspot_reports_table_exists(): void
    {
        $this->assertTrue(\Schema::hasTable('performance_hotspot_reports'));
    }

    public function test_noisy_enterprise_simulations_table_exists(): void
    {
        $this->assertTrue(\Schema::hasTable('noisy_enterprise_simulations'));
    }

    public function test_xdr_maturity_scorecards_table_exists(): void
    {
        $this->assertTrue(\Schema::hasTable('xdr_maturity_scorecards'));
    }

    public function test_xdr_readiness_report_artifacts_table_exists(): void
    {
        $this->assertTrue(\Schema::hasTable('xdr_readiness_report_artifacts'));
    }

    // -----------------------------------------------------------------------
    // Append-only integrity (no updated_at on the 8 append-only tables)
    // -----------------------------------------------------------------------

    public function test_detection_quality_scorecards_has_no_updated_at(): void
    {
        $this->assertFalse(\Schema::hasColumn('detection_quality_scorecards', 'updated_at'));
    }

    public function test_false_positive_negative_reports_has_no_updated_at(): void
    {
        $this->assertFalse(\Schema::hasColumn('false_positive_negative_reports', 'updated_at'));
    }

    public function test_telemetry_quality_scorecards_has_no_updated_at(): void
    {
        $this->assertFalse(\Schema::hasColumn('telemetry_quality_scorecards', 'updated_at'));
    }

    public function test_analyst_triage_simulation_runs_has_no_updated_at(): void
    {
        $this->assertFalse(\Schema::hasColumn('analyst_triage_simulation_runs', 'updated_at'));
    }

    public function test_performance_hotspot_reports_has_no_updated_at(): void
    {
        $this->assertFalse(\Schema::hasColumn('performance_hotspot_reports', 'updated_at'));
    }

    public function test_noisy_enterprise_simulations_has_no_updated_at(): void
    {
        $this->assertFalse(\Schema::hasColumn('noisy_enterprise_simulations', 'updated_at'));
    }

    public function test_xdr_maturity_scorecards_has_no_updated_at(): void
    {
        $this->assertFalse(\Schema::hasColumn('xdr_maturity_scorecards', 'updated_at'));
    }

    public function test_xdr_readiness_report_artifacts_has_no_updated_at(): void
    {
        $this->assertFalse(\Schema::hasColumn('xdr_readiness_report_artifacts', 'updated_at'));
    }

    // -----------------------------------------------------------------------
    // Service constants
    // -----------------------------------------------------------------------

    public function test_service_constants_are_correct(): void
    {
        $this->assertTrue(CodeLevelXdrMaturityService::ADVISORY_ONLY);
        $this->assertEquals(100, CodeLevelXdrMaturityService::MAX_FIXTURE_EVENTS);
        $this->assertEquals(50, CodeLevelXdrMaturityService::MAX_TRIAGE_ALERTS);
        $this->assertEquals(20, CodeLevelXdrMaturityService::MAX_HOTSPOT_QUERIES);
        $this->assertEquals(1000, CodeLevelXdrMaturityService::MAX_NOISE_EVENTS);
        $this->assertEquals(0.75, CodeLevelXdrMaturityService::QUALITY_PASS_THRESHOLD);
    }

    // -----------------------------------------------------------------------
    // No forbidden methods
    // -----------------------------------------------------------------------

    public function test_no_forbidden_methods_on_service(): void
    {
        $this->assertFalse(method_exists($this->svc, 'isolateHost'));
        $this->assertFalse(method_exists($this->svc, 'quarantineHost'));
        $this->assertFalse(method_exists($this->svc, 'executeShell'));
        $this->assertFalse(method_exists($this->svc, 'killProcess'));
        $this->assertFalse(method_exists($this->svc, 'autoRemediate'));
        $this->assertFalse(method_exists($this->svc, 'runRansomwareSimulation'));
        $this->assertFalse(method_exists($this->svc, 'dumpCredentials'));
        $this->assertFalse(method_exists($this->svc, 'executeExploit'));
        $this->assertFalse(method_exists($this->svc, 'injectPayload'));
        $this->assertFalse(method_exists($this->svc, 'makeHiddenAiDecision'));
    }

    // -----------------------------------------------------------------------
    // createSyntheticAttackFixture
    // -----------------------------------------------------------------------

    public function test_create_synthetic_attack_fixture_creates_record(): void
    {
        $events = [['type' => 'process_start', 'name' => 'powershell.exe']];
        $fixture = $this->svc->createSyntheticAttackFixture('phishing_to_script_execution', $events);

        $this->assertInstanceOf(SyntheticAttackFixture::class, $fixture);
        $this->assertEquals('phishing_to_script_execution', $fixture->fixture_scenario);
        $this->assertTrue($fixture->is_lab_safe);
        $this->assertFalse($fixture->is_destructive);
        $this->assertFalse($fixture->has_live_execution);
        $this->assertFalse($fixture->has_destructive_payload);
        $this->assertEquals('draft', $fixture->fixture_state);
        $this->assertEquals(1, $fixture->total_events);
    }

    public function test_fixture_events_bounded_to_max(): void
    {
        $events = array_fill(0, 200, ['type' => 'event']);
        $fixture = $this->svc->createSyntheticAttackFixture('lolbin_execution_chain', $events);

        $this->assertLessThanOrEqual(CodeLevelXdrMaturityService::MAX_FIXTURE_EVENTS, $fixture->total_events);
        $this->assertEquals(100, $fixture->total_events);
    }

    public function test_fixture_rejects_unknown_scenario(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->createSyntheticAttackFixture('deploy_ransomware', []);
    }

    public function test_fixture_is_always_lab_safe_non_destructive(): void
    {
        $fixture = $this->svc->createSyntheticAttackFixture('persistence_chain', [['type' => 'reg_write']]);
        $this->assertTrue($fixture->is_lab_safe);
        $this->assertFalse($fixture->is_destructive);
    }

    public function test_all_fixture_scenarios_are_valid(): void
    {
        foreach (SyntheticAttackFixture::FIXTURE_SCENARIOS as $scenario) {
            $fixture = $this->svc->createSyntheticAttackFixture($scenario, [['type' => 'event']]);
            $this->assertEquals($scenario, $fixture->fixture_scenario);
        }
    }

    // -----------------------------------------------------------------------
    // scoreDetectionQuality
    // -----------------------------------------------------------------------

    public function test_score_detection_quality_creates_scorecard(): void
    {
        $scorecard = $this->svc->scoreDetectionQuality([
            'true_positives'        => 80,
            'false_positives'       => 10,
            'false_negatives'       => 5,
            'detection_coverage'    => 0.85,
            'attack_coverage'       => 0.70,
            'evidence_completeness' => 0.90,
            'confidence_stability'  => 0.88,
        ]);

        $this->assertInstanceOf(DetectionQualityScorecard::class, $scorecard);
        $this->assertTrue($scorecard->is_advisory);
        $this->assertGreaterThanOrEqual(0.0, $scorecard->precision_score);
        $this->assertLessThanOrEqual(1.0, $scorecard->precision_score);
        $this->assertGreaterThanOrEqual(0.0, $scorecard->overall_quality_score);
        $this->assertLessThanOrEqual(1.0, $scorecard->overall_quality_score);
    }

    public function test_detection_scorecard_is_advisory(): void
    {
        $s = $this->svc->scoreDetectionQuality(['true_positives' => 10]);
        $this->assertTrue($s->is_advisory);
    }

    public function test_amplification_ratio_capped(): void
    {
        $s = $this->svc->scoreDetectionQuality(['alert_amplification_ratio' => 9999.0]);
        $this->assertLessThanOrEqual(CodeLevelXdrMaturityService::MAX_AMPLIFICATION_RATIO, $s->alert_amplification_ratio);
    }

    // -----------------------------------------------------------------------
    // analyzeFalsePositiveNegative
    // -----------------------------------------------------------------------

    public function test_analyze_false_positive_negative_creates_report(): void
    {
        $findings = [
            ['type' => 'noisy_rule'],
            ['type' => 'repeated_benign'],
            ['type' => 'missed_chain'],
            ['type' => 'weak_evidence'],
        ];
        $report = $this->svc->analyzeFalsePositiveNegative('rule-abc-123', $findings);

        $this->assertInstanceOf(FalsePositiveNegativeReport::class, $report);
        $this->assertTrue($report->advisory_only);
        $this->assertTrue($report->no_auto_suppression);
        $this->assertTrue($report->no_auto_closure);
        $this->assertEquals(1, $report->noisy_rule_count);
        $this->assertEquals(1, $report->repeated_benign_count);
        $this->assertEquals(1, $report->missed_chain_count);
        $this->assertEquals(1, $report->weak_evidence_count);
    }

    public function test_fpfn_report_no_auto_suppression(): void
    {
        $r = $this->svc->analyzeFalsePositiveNegative('rule-x', []);
        $this->assertTrue($r->no_auto_suppression);
        $this->assertTrue($r->no_auto_closure);
    }

    public function test_fpfn_rate_is_bounded(): void
    {
        $many = array_fill(0, 100, ['type' => 'noisy_rule']);
        $r = $this->svc->analyzeFalsePositiveNegative('rule-z', $many);
        $this->assertLessThanOrEqual(1.0, $r->fp_prevalence_rate);
    }

    // -----------------------------------------------------------------------
    // scoreTelemetryQuality
    // -----------------------------------------------------------------------

    public function test_score_telemetry_quality_creates_scorecard(): void
    {
        $events = [
            ['trace_id' => 'tr-1', 'event_id' => 'ev-1', 'timestamp' => now()->toIso8601String(),
             'host_id' => 'h1', 'parent_process_id' => 'pp1', 'enrichment' => ['geo' => 'US'], 'tenant_id' => 't1'],
        ];
        $sc = $this->svc->scoreTelemetryQuality($events, 'tenant-1');

        $this->assertInstanceOf(TelemetryQualityScorecard::class, $sc);
        $this->assertTrue($sc->is_advisory);
        $this->assertEquals(1, $sc->events_sampled);
        $this->assertGreaterThanOrEqual(0.0, $sc->overall_quality_score);
        $this->assertLessThanOrEqual(1.0, $sc->overall_quality_score);
    }

    public function test_telemetry_events_bounded_to_max(): void
    {
        $events = array_fill(0, 2000, ['trace_id' => 'tr']);
        $sc = $this->svc->scoreTelemetryQuality($events);
        $this->assertLessThanOrEqual(CodeLevelXdrMaturityService::MAX_NOISE_EVENTS, $sc->events_sampled);
    }

    // -----------------------------------------------------------------------
    // simulateAnalystTriage
    // -----------------------------------------------------------------------

    public function test_simulate_analyst_triage_creates_run(): void
    {
        $alerts = array_fill(0, 10, ['alert_id' => 'a1', 'severity' => 'high']);
        $run = $this->svc->simulateAnalystTriage('alert_flood_triage', $alerts);

        $this->assertInstanceOf(AnalystTriageSimulationRun::class, $run);
        $this->assertTrue($run->no_real_user_mutation);
        $this->assertTrue($run->no_hidden_suppression);
        $this->assertTrue($run->no_auto_incident_closure);
        $this->assertEquals(10, $run->alerts_simulated);
    }

    public function test_triage_alerts_bounded_to_max(): void
    {
        $alerts = array_fill(0, 200, ['alert_id' => 'a']);
        $run = $this->svc->simulateAnalystTriage('alert_flood_triage', $alerts);
        $this->assertLessThanOrEqual(CodeLevelXdrMaturityService::MAX_TRIAGE_ALERTS, $run->alerts_simulated);
    }

    public function test_triage_rejects_unknown_scenario(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->simulateAnalystTriage('auto_close_all', []);
    }

    public function test_triage_no_real_user_mutation(): void
    {
        $run = $this->svc->simulateAnalystTriage('shift_handoff', [['alert_id' => 'a1']]);
        $this->assertTrue($run->no_real_user_mutation);
        $this->assertTrue($run->no_auto_incident_closure);
    }

    // -----------------------------------------------------------------------
    // analyzePerformanceHotspots
    // -----------------------------------------------------------------------

    public function test_analyze_performance_hotspots_creates_report(): void
    {
        $metrics = [
            ['type' => 'slow_detection_chain', 'ms' => 1200],
            ['type' => 'expensive_graph', 'ms' => 800],
            ['type' => 'alert_spike', 'count' => 50],
        ];
        $r = $this->svc->analyzePerformanceHotspots($metrics);

        $this->assertInstanceOf(PerformanceHotspotReport::class, $r);
        $this->assertTrue($r->advisory_only);
        $this->assertTrue($r->no_destructive_load_test);
        $this->assertEquals(1, $r->slow_chain_count);
        $this->assertEquals(1, $r->expensive_graph_count);
        $this->assertEquals(1, $r->alert_spike_count);
    }

    public function test_hotspot_severity_bounded(): void
    {
        $metrics = array_fill(0, 100, ['type' => 'slow_detection_chain', 'ms' => 5000]);
        $r = $this->svc->analyzePerformanceHotspots($metrics);
        $this->assertLessThanOrEqual(1.0, $r->hotspot_severity_score);
    }

    // -----------------------------------------------------------------------
    // runNoisyEnterpriseSimulation
    // -----------------------------------------------------------------------

    public function test_run_noisy_enterprise_simulation_creates_record(): void
    {
        $sim = $this->svc->runNoisyEnterpriseSimulation('patch_management_wave', 500);

        $this->assertInstanceOf(NoisyEnterpriseSimulation::class, $sim);
        $this->assertTrue($sim->is_synthetic);
        $this->assertTrue($sim->is_lab_safe);
        $this->assertFalse($sim->has_destructive_content);
        $this->assertEquals(500, $sim->events_generated);
    }

    public function test_noisy_sim_events_bounded(): void
    {
        $sim = $this->svc->runNoisyEnterpriseSimulation('dns_burst', 9999);
        $this->assertLessThanOrEqual(CodeLevelXdrMaturityService::MAX_NOISE_EVENTS, $sim->events_generated);
    }

    public function test_noisy_sim_rejects_unknown_scenario(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->runNoisyEnterpriseSimulation('deploy_malware', 100);
    }

    public function test_noisy_sim_has_no_destructive_content(): void
    {
        foreach (NoisyEnterpriseSimulation::NOISE_SCENARIOS as $scenario) {
            $sim = $this->svc->runNoisyEnterpriseSimulation($scenario, 10);
            $this->assertFalse($sim->has_destructive_content, "Scenario {$scenario} must not have destructive content");
            $this->assertTrue($sim->is_lab_safe);
        }
    }

    // -----------------------------------------------------------------------
    // generateXdrReadinessReport
    // -----------------------------------------------------------------------

    public function test_generate_xdr_readiness_report_creates_artifact(): void
    {
        $artifact = $this->svc->generateXdrReadinessReport([
            'report_type'     => 'xdr_maturity_scorecard',
            'summary'         => ['rules' => 133, 'domains' => 155],
            'scores'          => ['detection' => '85%', 'telemetry' => '79%'],
            'evidence_refs'   => ['scorecard-uuid-1'],
        ]);

        $this->assertInstanceOf(XdrReadinessReportArtifact::class, $artifact);
        $this->assertTrue($artifact->is_advisory);
        $this->assertFalse($artifact->is_fabricated);
        $this->assertTrue($artifact->evidence_linked);
        $this->assertNotEmpty($artifact->artifact_hash);
        $this->assertNotEmpty($artifact->report_markdown);
    }

    public function test_readiness_report_rejects_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->generateXdrReadinessReport(['report_type' => 'fabricated_prod_metrics']);
    }

    // -----------------------------------------------------------------------
    // generateXdrMaturityScorecard
    // -----------------------------------------------------------------------

    public function test_generate_xdr_maturity_scorecard_creates_record(): void
    {
        $sc = $this->svc->generateXdrMaturityScorecard([
            'detection_quality'   => 0.85,
            'telemetry_quality'   => 0.79,
            'fp_fn_health'        => 0.82,
            'triage_realism'      => 0.77,
            'performance_health'  => 0.88,
            'noise_resilience'    => 0.81,
            'attack_coverage'     => 0.74,
        ]);

        $this->assertInstanceOf(XdrMaturityScorecard::class, $sc);
        $this->assertTrue($sc->is_advisory);
        $this->assertGreaterThanOrEqual(0.0, $sc->overall_maturity_score);
        $this->assertLessThanOrEqual(1.0, $sc->overall_maturity_score);
        $this->assertContains($sc->maturity_tier, XdrMaturityScorecard::MATURITY_TIERS);
    }

    public function test_maturity_score_is_deterministic(): void
    {
        $scores = ['detection_quality' => 0.80, 'telemetry_quality' => 0.80,
                   'fp_fn_health' => 0.80, 'triage_realism' => 0.80,
                   'performance_health' => 0.80, 'noise_resilience' => 0.80, 'attack_coverage' => 0.80];
        $sc1 = $this->svc->generateXdrMaturityScorecard($scores);
        $sc2 = $this->svc->generateXdrMaturityScorecard($scores);
        $this->assertEquals($sc1->overall_maturity_score, $sc2->overall_maturity_score);
        $this->assertEquals($sc1->maturity_tier, $sc2->maturity_tier);
    }

    // -----------------------------------------------------------------------
    // dashboardStats
    // -----------------------------------------------------------------------

    public function test_dashboard_stats_returns_expected_keys(): void
    {
        $stats = $this->svc->dashboardStats();

        $this->assertArrayHasKey('total_fixtures', $stats);
        $this->assertArrayHasKey('detection_scorecards', $stats);
        $this->assertArrayHasKey('avg_detection_quality', $stats);
        $this->assertArrayHasKey('telemetry_scorecards', $stats);
        $this->assertArrayHasKey('fpfn_reports', $stats);
        $this->assertArrayHasKey('triage_simulations', $stats);
        $this->assertArrayHasKey('maturity_scorecards', $stats);
        $this->assertArrayHasKey('readiness_artifacts', $stats);
    }

    // -----------------------------------------------------------------------
    // Threat Hunting domain count
    // -----------------------------------------------------------------------

    public function test_threat_hunting_service_has_155_domains(): void
    {
        $this->assertCount(155, ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_xdr_maturity_domains_are_registered(): void
    {
        $domains = ThreatHuntingService::SUPPORTED_DOMAINS;
        $this->assertContains('synthetic_attack_fixtures', $domains);
        $this->assertContains('detection_quality_scorecards', $domains);
        $this->assertContains('false_positive_negative_reports', $domains);
        $this->assertContains('telemetry_quality_scorecards', $domains);
        $this->assertContains('xdr_maturity_scorecards', $domains);
    }

    // -----------------------------------------------------------------------
    // Route auth checks
    // -----------------------------------------------------------------------

    public function test_xdr_maturity_dashboard_requires_auth(): void
    {
        $this->get('/xdr-maturity')->assertRedirect('/login');
    }

    public function test_xdr_maturity_fixtures_requires_auth(): void
    {
        $this->get('/xdr-maturity/fixtures')->assertRedirect('/login');
    }

    public function test_xdr_maturity_detection_requires_auth(): void
    {
        $this->get('/xdr-maturity/detection')->assertRedirect('/login');
    }

    public function test_xdr_maturity_report_requires_auth(): void
    {
        $this->get('/xdr-maturity/report')->assertRedirect('/login');
    }

    // -----------------------------------------------------------------------
    // Append-only integrity — records cannot be deleted
    // -----------------------------------------------------------------------

    public function test_detection_scorecards_are_append_only(): void
    {
        $this->svc->scoreDetectionQuality(['true_positives' => 5]);
        $this->svc->scoreDetectionQuality(['true_positives' => 10]);
        $this->assertEquals(2, DetectionQualityScorecard::count());
    }

    public function test_noisy_simulations_are_append_only(): void
    {
        $this->svc->runNoisyEnterpriseSimulation('dns_burst', 100);
        $this->svc->runNoisyEnterpriseSimulation('admin_login_burst', 50);
        $this->assertEquals(2, NoisyEnterpriseSimulation::count());
    }
}
