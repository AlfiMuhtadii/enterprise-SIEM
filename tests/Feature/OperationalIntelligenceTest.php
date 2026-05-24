<?php

namespace Tests\Feature;

use App\Models\OperationalIntelligenceSnapshot;
use App\Models\AnalystInvestigationSummary;
use App\Models\DetectionConfidenceHistory;
use App\Models\FalsePositiveDriftReport;
use App\Models\AttackProgressionScore;
use App\Models\ChainedInvestigationView;
use App\Models\ReplayConfidenceValidation;
use App\Models\SuppressionEffectivenessReport;
use App\Models\AnalystAcknowledgmentPattern;
use App\Services\OperationalIntelligenceService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class OperationalIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private OperationalIntelligenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OperationalIntelligenceService::class);
    }

    // =========================================================================
    // Hard constraint: no forbidden methods
    // =========================================================================

    public function test_no_isolate_host_method(): void
    {
        $this->assertFalse(method_exists(OperationalIntelligenceService::class, 'isolateHost'));
    }

    public function test_no_quarantine_host_method(): void
    {
        $this->assertFalse(method_exists(OperationalIntelligenceService::class, 'quarantineHost'));
    }

    public function test_no_execute_shell_method(): void
    {
        $this->assertFalse(method_exists(OperationalIntelligenceService::class, 'executeShell'));
    }

    public function test_no_kill_process_method(): void
    {
        $this->assertFalse(method_exists(OperationalIntelligenceService::class, 'killProcess'));
    }

    public function test_no_auto_remediate_method(): void
    {
        $this->assertFalse(method_exists(OperationalIntelligenceService::class, 'autoRemediate'));
    }

    public function test_no_hidden_suppression_mutation_method(): void
    {
        $this->assertFalse(method_exists(OperationalIntelligenceService::class, 'hiddenlySuppress'));
    }

    public function test_no_offensive_payload_method(): void
    {
        $this->assertFalse(method_exists(OperationalIntelligenceService::class, 'executeOffensivePayload'));
    }

    public function test_no_black_box_ai_decision_method(): void
    {
        $this->assertFalse(method_exists(OperationalIntelligenceService::class, 'blackBoxDecide'));
    }

    public function test_no_unsafe_automated_response_method(): void
    {
        $this->assertFalse(method_exists(OperationalIntelligenceService::class, 'executeAutomatedResponse'));
    }

    public function test_no_unrestricted_graph_traversal_method(): void
    {
        $this->assertFalse(method_exists(OperationalIntelligenceService::class, 'traverseUnbounded'));
    }

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(OperationalIntelligenceService::ADVISORY_ONLY);
    }

    // =========================================================================
    // Operational intelligence snapshot
    // =========================================================================

    public function test_record_snapshot_creates_record(): void
    {
        $snap = $this->service->recordSnapshot('t1', 'daily', [
            'active_rules'         => 12,
            'shadow_rules'         => 81,
            'avg_confidence'       => 0.78,
            'alert_count'          => 100,
            'false_positive_count' => 5,
        ]);
        $this->assertEquals('daily', $snap->snapshot_type);
        $this->assertEquals(12, $snap->active_rules);
        $this->assertEquals(0.05, $snap->false_positive_rate);
        $this->assertTrue($snap->is_advisory);
    }

    public function test_record_snapshot_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordSnapshot('t1', 'hourly', []);
    }

    public function test_coverage_score_is_bounded_to_1(): void
    {
        $snap = $this->service->recordSnapshot('t1', 'daily', [
            'active_rules' => 200,
            'shadow_rules' => 200,
        ]);
        $this->assertLessThanOrEqual(1.0, $snap->coverage_score);
    }

    public function test_snapshot_is_append_only(): void
    {
        $snap = $this->service->recordSnapshot('t1', 'daily', []);
        $model = OperationalIntelligenceSnapshot::where('snapshot_id', $snap->snapshot_id)->first();
        $this->expectException(LogicException::class);
        $model->alert_count = 99;
        $model->save();
    }

    // =========================================================================
    // Analyst investigation summary
    // =========================================================================

    public function test_record_investigation_summary_creates_record(): void
    {
        $summary = $this->service->recordInvestigationSummary(
            't1', 'analyst-1', 'inv-001', 'confirmed',
            ['attack_tactic' => 'TA0001', 'confidence_score' => 0.85]
        );
        $this->assertEquals('confirmed', $summary->verdict);
        $this->assertEquals(0.85, $summary->confidence_score);
        $this->assertTrue($summary->replay_safe);
        $this->assertTrue($summary->is_advisory);
    }

    public function test_investigation_summary_rejects_invalid_verdict(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordInvestigationSummary('t1', 'a1', 'inv-001', 'resolved_invalid');
    }

    public function test_confidence_score_is_clamped_to_range(): void
    {
        $summary = $this->service->recordInvestigationSummary(
            't1', 'a1', 'inv-001', 'confirmed', ['confidence_score' => 2.5]
        );
        $this->assertLessThanOrEqual(1.0, $summary->confidence_score);
        $this->assertGreaterThanOrEqual(0.0, $summary->confidence_score);
    }

    public function test_investigation_summary_is_append_only(): void
    {
        $summary = $this->service->recordInvestigationSummary('t1', 'a1', 'inv-001', 'dismissed');
        $model = AnalystInvestigationSummary::where('summary_id', $summary->summary_id)->first();
        $this->expectException(LogicException::class);
        $model->verdict = 'confirmed';
        $model->save();
    }

    // =========================================================================
    // Detection confidence history
    // =========================================================================

    public function test_record_confidence_history_creates_record(): void
    {
        $h = $this->service->recordConfidenceHistory('RULE_001', 't1', 0.75, 'replay_validated', true, 0.02);
        $this->assertEquals(0.75, $h->confidence_value);
        $this->assertEquals('replay_validated', $h->confidence_source);
        $this->assertTrue($h->replay_consistent);
        $this->assertTrue($h->is_advisory);
    }

    public function test_confidence_history_rejects_invalid_source(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordConfidenceHistory('RULE_001', 't1', 0.75, 'unknown_source');
    }

    public function test_confidence_value_is_clamped(): void
    {
        $h = $this->service->recordConfidenceHistory('RULE_001', 't1', 1.5, 'rule_base');
        $this->assertEquals(1.0, $h->confidence_value);
    }

    public function test_confidence_history_is_append_only(): void
    {
        $h = $this->service->recordConfidenceHistory('RULE_001', 't1', 0.7, 'rule_base');
        $model = DetectionConfidenceHistory::where('history_id', $h->history_id)->first();
        $this->expectException(LogicException::class);
        $model->confidence_value = 0.9;
        $model->save();
    }

    public function test_confidence_history_uses_explicit_table(): void
    {
        $h = $this->service->recordConfidenceHistory('RULE_001', 't1', 0.7, 'rule_base');
        $this->assertDatabaseHas('detection_confidence_history', ['history_id' => $h->history_id]);
    }

    // =========================================================================
    // False-positive drift reports
    // =========================================================================

    public function test_fp_drift_report_direction_increasing(): void
    {
        $r = $this->service->recordFpDriftReport('RULE_001', 't1', 0.20, 0.05);
        $this->assertEquals('increasing', $r->drift_direction);
        $this->assertTrue($r->suppression_recommended);
    }

    public function test_fp_drift_report_direction_decreasing(): void
    {
        $r = $this->service->recordFpDriftReport('RULE_001', 't1', 0.03, 0.08);
        $this->assertEquals('decreasing', $r->drift_direction);
    }

    public function test_fp_drift_report_direction_stable(): void
    {
        $r = $this->service->recordFpDriftReport('RULE_001', 't1', 0.05, 0.05);
        $this->assertEquals('stable', $r->drift_direction);
    }

    public function test_fp_drift_suppression_not_recommended_below_threshold(): void
    {
        $r = $this->service->recordFpDriftReport('RULE_001', 't1', 0.06, 0.05);
        $this->assertFalse($r->suppression_recommended);
    }

    public function test_fp_drift_report_is_append_only(): void
    {
        $r = $this->service->recordFpDriftReport('RULE_001', 't1', 0.10, 0.05);
        $model = FalsePositiveDriftReport::where('report_id', $r->report_id)->first();
        $this->expectException(LogicException::class);
        $model->drift_direction = 'stable';
        $model->save();
    }

    // =========================================================================
    // Attack progression scoring
    // =========================================================================

    public function test_score_attack_progression_creates_record(): void
    {
        $score = $this->service->scoreAttackProgression('t1', 'chain-001', [
            'TA0001', 'TA0002', 'TA0006',
        ]);
        $this->assertEquals(3, $score->tactic_count);
        $this->assertTrue($score->chained_confirmed);
        $this->assertTrue($score->is_advisory);
    }

    public function test_attack_progression_not_chained_with_one_tactic(): void
    {
        $score = $this->service->scoreAttackProgression('t1', 'chain-002', ['TA0001']);
        $this->assertFalse($score->chained_confirmed);
    }

    public function test_attack_progression_sequence_overflow_throws(): void
    {
        $this->expectException(\OverflowException::class);
        $this->service->scoreAttackProgression('t1', 'chain-003', array_fill(0, 11, 'TA0001'));
    }

    public function test_attack_progression_sequence_at_max_succeeds(): void
    {
        $score = $this->service->scoreAttackProgression('t1', 'chain-004', array_fill(0, 10, 'TA0001'));
        $this->assertEquals(10, $score->tactic_count);
    }

    public function test_progression_score_is_bounded(): void
    {
        $score = $this->service->scoreAttackProgression('t1', 'chain-005', array_fill(0, 10, 'TA0001'));
        $this->assertLessThanOrEqual(1.0, $score->progression_score);
    }

    public function test_attack_progression_is_append_only(): void
    {
        $score = $this->service->scoreAttackProgression('t1', 'chain-006', ['TA0001', 'TA0002']);
        $model = AttackProgressionScore::where('score_id', $score->score_id)->first();
        $this->expectException(LogicException::class);
        $model->progression_score = 0.99;
        $model->save();
    }

    // =========================================================================
    // Chained investigation views (mutable)
    // =========================================================================

    public function test_chained_view_is_mutable(): void
    {
        $view = $this->service->createChainedView('t1', 'inv-001');
        $view->update(['status' => 'archived', 'node_count' => 5]);
        $this->assertEquals('archived', $view->fresh()->status);
    }

    public function test_chained_view_bounded_traversal_always_true(): void
    {
        $view = $this->service->createChainedView('t1', 'inv-001');
        $this->assertTrue($view->bounded_traversal);
    }

    public function test_chained_view_depth_capped_at_max(): void
    {
        $view = $this->service->createChainedView('t1', 'inv-001', ['depth' => 999]);
        $this->assertLessThanOrEqual(ChainedInvestigationView::MAX_DEPTH, $view->depth);
    }

    public function test_archive_chained_view_updates_status(): void
    {
        $view = $this->service->createChainedView('t1', 'inv-001');
        $archived = $this->service->archiveChainedView($view);
        $this->assertEquals('archived', $archived->status);
    }

    public function test_chained_view_is_advisory(): void
    {
        $view = $this->service->createChainedView('t1', 'inv-001');
        $this->assertTrue($view->is_advisory);
    }

    // =========================================================================
    // Replay confidence validation
    // =========================================================================

    public function test_replay_consistent_when_delta_within_threshold(): void
    {
        $v = $this->service->recordReplayConfidenceValidation('RULE_001', 't1', 0.75, 0.77);
        $this->assertTrue($v->replay_consistent);
        $this->assertEquals('consistent', $v->verdict);
    }

    public function test_replay_drifted_when_delta_exceeds_threshold(): void
    {
        $v = $this->service->recordReplayConfidenceValidation('RULE_001', 't1', 0.75, 0.40);
        $this->assertFalse($v->replay_consistent);
        $this->assertEquals('drifted', $v->verdict);
    }

    public function test_replay_confidence_delta_is_calculated(): void
    {
        $v = $this->service->recordReplayConfidenceValidation('RULE_001', 't1', 0.70, 0.80);
        $this->assertEqualsWithDelta(0.10, $v->confidence_delta, 0.001);
    }

    public function test_replay_confidence_validation_is_append_only(): void
    {
        $v = $this->service->recordReplayConfidenceValidation('RULE_001', 't1', 0.75, 0.75);
        $model = ReplayConfidenceValidation::where('validation_id', $v->validation_id)->first();
        $this->expectException(LogicException::class);
        $model->verdict = 'drifted';
        $model->save();
    }

    // =========================================================================
    // Suppression effectiveness
    // =========================================================================

    public function test_suppression_effectiveness_score_is_ratio(): void
    {
        $r = $this->service->recordSuppressionEffectiveness(
            'RULE_001', 't1', 'rule_id|user', 100, 90, 0
        );
        $this->assertEqualsWithDelta(0.90, $r->effectiveness_score, 0.001);
        $this->assertTrue($r->suppression_safe);
    }

    public function test_suppression_not_safe_when_tp_suppressed(): void
    {
        $r = $this->service->recordSuppressionEffectiveness(
            'RULE_001', 't1', 'rule_id|user', 100, 90, 5
        );
        $this->assertFalse($r->suppression_safe);
    }

    public function test_suppression_effectiveness_zero_count(): void
    {
        $r = $this->service->recordSuppressionEffectiveness(
            'RULE_001', 't1', 'rule_id|user', 0, 0, 0
        );
        $this->assertEquals(0.0, $r->effectiveness_score);
    }

    public function test_suppression_effectiveness_is_append_only(): void
    {
        $r = $this->service->recordSuppressionEffectiveness('RULE_001', 't1', 'key', 10, 8, 0);
        $model = SuppressionEffectivenessReport::where('report_id', $r->report_id)->first();
        $this->expectException(LogicException::class);
        $model->effectiveness_score = 0.5;
        $model->save();
    }

    // =========================================================================
    // Analyst acknowledgment patterns
    // =========================================================================

    public function test_acknowledgment_pattern_creates_record(): void
    {
        $p = $this->service->recordAcknowledgmentPattern('analyst-1', 't1', 'RULE_001', 'dismissed_fp', 30.5);
        $this->assertEquals('dismissed_fp', $p->acknowledgment_type);
        $this->assertEquals(30.5, $p->response_latency_seconds);
        $this->assertTrue($p->replay_consistent);
        $this->assertTrue($p->is_advisory);
    }

    public function test_acknowledgment_pattern_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordAcknowledgmentPattern('analyst-1', 't1', 'RULE_001', 'unknown_type', 30.0);
    }

    public function test_acknowledgment_pattern_is_append_only(): void
    {
        $p = $this->service->recordAcknowledgmentPattern('analyst-1', 't1', 'RULE_001', 'confirmed_tp', 10.0);
        $model = AnalystAcknowledgmentPattern::where('pattern_id', $p->pattern_id)->first();
        $this->expectException(LogicException::class);
        $model->acknowledgment_type = 'dismissed_fp';
        $model->save();
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_returns_expected_keys(): void
    {
        $stats = $this->service->dashboardStats();
        $this->assertArrayHasKey('snapshots', $stats);
        $this->assertArrayHasKey('confirmed_tp', $stats);
        $this->assertArrayHasKey('dismissed_fp', $stats);
        $this->assertArrayHasKey('drift_reports', $stats);
        $this->assertArrayHasKey('attack_chains', $stats);
        $this->assertArrayHasKey('replay_consistent', $stats);
        $this->assertArrayHasKey('replay_drifted', $stats);
        $this->assertArrayHasKey('active_views', $stats);
    }

    // =========================================================================
    // ThreatHunting domain integration
    // =========================================================================

    public function test_threat_hunting_has_100_supported_domains(): void
    {
        $this->assertCount(145, app(ThreatHuntingService::class)->supportedDomains());
    }

    public function test_operational_intelligence_snapshots_domain_supported(): void
    {
        $this->assertContains('operational_intelligence_snapshots', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_analyst_investigation_summaries_domain_supported(): void
    {
        $this->assertContains('analyst_investigation_summaries', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_detection_confidence_history_domain_supported(): void
    {
        $this->assertContains('detection_confidence_history', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_false_positive_drift_reports_domain_supported(): void
    {
        $this->assertContains('false_positive_drift_reports', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_attack_progression_scores_domain_supported(): void
    {
        $this->assertContains('attack_progression_scores', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    // =========================================================================
    // Rule registry expansion
    // =========================================================================

    public function test_rule_registry_has_at_least_133_rules(): void
    {
        $path = base_path('docs/detection/rules/registry.v1.json');
        $registry = json_decode(file_get_contents($path), true);
        $this->assertGreaterThanOrEqual(133, count($registry['rules']));
    }

    public function test_all_new_rules_are_shadow_only(): void
    {
        $path = base_path('docs/detection/rules/registry.v1.json');
        $registry = json_decode(file_get_contents($path), true);
        $newRuleIds = [
            'CRED_KERBEROS_ANOMALOUS_TGT_REQUEST',
            'CRED_PASS_THE_HASH_INDICATOR',
            'LATERAL_RDP_ANOMALOUS_SOURCE',
            'SAAS_MASS_EXPORT_ANOMALY',
            'CONTAINER_RUNTIME_DRIFT',
        ];
        $ruleMap = array_column($registry['rules'], null, 'rule_id');
        foreach ($newRuleIds as $ruleId) {
            $this->assertArrayHasKey($ruleId, $ruleMap, "Rule {$ruleId} not found");
            $this->assertEquals('shadow', $ruleMap[$ruleId]['status'], "Rule {$ruleId} must be shadow");
            $this->assertTrue($ruleMap[$ruleId]['shadow_only'], "Rule {$ruleId} must be shadow_only");
        }
    }

    public function test_active_allowlist_remains_empty(): void
    {
        $path = base_path('scripts/xdr_rule_registry_validate.py');
        $content = file_get_contents($path);
        $this->assertStringContainsString('frozenset()', $content, 'ACTIVE_ALLOWLIST must remain empty');
    }

    // =========================================================================
    // Route access
    // =========================================================================

    public function test_op_intel_dashboard_requires_auth(): void
    {
        $this->get(route('op-intel.dashboard'))->assertRedirect();
    }

    public function test_op_intel_dashboard_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('op-intel.dashboard'))->assertOk();
    }

    public function test_op_intel_confidence_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('op-intel.confidence'))->assertOk();
    }

    public function test_op_intel_investigations_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('op-intel.investigations'))->assertOk();
    }

    public function test_op_intel_progression_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('op-intel.progression'))->assertOk();
    }

    // =========================================================================
    // Advisory notice in views
    // =========================================================================

    public function test_dashboard_view_contains_advisory_notice(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('op-intel.dashboard'))
            ->assertSee('advisory-only');
    }
}

