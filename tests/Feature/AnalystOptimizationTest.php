<?php

namespace Tests\Feature;

use App\Models\AnalystWorkloadSnapshot;
use App\Models\AlertPrioritizationScore;
use App\Models\FalsePositiveTuningReport;
use App\Models\AnalystAcknowledgmentAudit;
use App\Models\EscalationQualityReview;
use App\Models\InvestigationErgonomicView;
use App\Models\AlertRecurrenceReport;
use App\Models\OperationalFatigueIndicator;
use App\Models\ShiftHandoffValidation;
use App\Services\AnalystOptimizationService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Traits\AssertsAdvisoryOnlyConstraints;
use Tests\TestCase;

class AnalystOptimizationTest extends TestCase
{
    use RefreshDatabase, AssertsAdvisoryOnlyConstraints;

    private AnalystOptimizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AnalystOptimizationService::class);
    }

    protected function getAdvisoryServiceClass(): string
    {
        return AnalystOptimizationService::class;
    }

    // =========================================================================
    // Hard constraint: no forbidden methods
    // =========================================================================

    public function test_no_hidden_suppression_method(): void
    {
        $this->assertFalse(method_exists(AnalystOptimizationService::class, 'hiddenlySuppress'));
    }

    public function test_no_automatic_incident_closure_method(): void
    {
        $this->assertFalse(method_exists(AnalystOptimizationService::class, 'autoCloseIncident'));
    }

    public function test_no_black_box_prioritization_method(): void
    {
        $this->assertFalse(method_exists(AnalystOptimizationService::class, 'blackBoxPrioritize'));
    }

    public function test_no_destructive_alert_deletion_method(): void
    {
        $this->assertFalse(method_exists(AnalystOptimizationService::class, 'deleteAlert'));
    }

    public function test_no_unsafe_analyst_bypass_method(): void
    {
        $this->assertFalse(method_exists(AnalystOptimizationService::class, 'bypassAnalystQueue'));
    }

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(AnalystOptimizationService::ADVISORY_ONLY);
    }

    // =========================================================================
    // Workload snapshots
    // =========================================================================

    public function test_workload_snapshot_creates_record(): void
    {
        $snap = $this->service->recordWorkloadSnapshot('analyst-1', 't1', [
            'open_investigations'    => 5,
            'pending_acknowledgments'=> 3,
            'escalation_queue_depth' => 2,
        ]);
        $this->assertEquals('analyst-1', $snap->analyst_id);
        $this->assertTrue($snap->is_advisory);
        $this->assertGreaterThanOrEqual(0.0, $snap->workload_score);
        $this->assertLessThanOrEqual(1.0, $snap->workload_score);
    }

    public function test_workload_overload_triggers_at_threshold(): void
    {
        $snap = $this->service->recordWorkloadSnapshot('analyst-1', 't1', [
            'open_investigations'    => AnalystOptimizationService::MAX_INVESTIGATIONS_PER_ANALYST,
            'pending_acknowledgments'=> 20,
            'escalation_queue_depth' => 10,
        ]);
        $this->assertTrue($snap->overload_indicator);
    }

    public function test_workload_not_overloaded_with_light_load(): void
    {
        $snap = $this->service->recordWorkloadSnapshot('analyst-1', 't1', [
            'open_investigations' => 2,
        ]);
        $this->assertFalse($snap->overload_indicator);
    }

    public function test_workload_snapshot_is_append_only(): void
    {
        $snap = $this->service->recordWorkloadSnapshot('analyst-1', 't1', []);
        $model = AnalystWorkloadSnapshot::where('snapshot_id', $snap->snapshot_id)->first();
        $this->expectException(LogicException::class);
        $model->workload_score = 0.99;
        $model->save();
    }

    // =========================================================================
    // Alert prioritization scoring
    // =========================================================================

    public function test_prioritization_score_creates_record(): void
    {
        $score = $this->service->scoreAlertPrioritization('alert-001', 't1', 'RULE_001', 0.90);
        $this->assertEquals('critical', $score->priority_tier);
        $this->assertTrue($score->is_advisory);
    }

    public function test_prioritization_tier_mapping(): void
    {
        $critical = $this->service->scoreAlertPrioritization('a1', 't1', 'R1', 0.90);
        $high     = $this->service->scoreAlertPrioritization('a2', 't1', 'R1', 0.70);
        $medium   = $this->service->scoreAlertPrioritization('a3', 't1', 'R1', 0.50);
        $low      = $this->service->scoreAlertPrioritization('a4', 't1', 'R1', 0.20);

        $this->assertEquals('critical', $critical->priority_tier);
        $this->assertEquals('high',     $high->priority_tier);
        $this->assertEquals('medium',   $medium->priority_tier);
        $this->assertEquals('low',      $low->priority_tier);
    }

    public function test_prioritization_score_is_bounded_to_1(): void
    {
        $score = $this->service->scoreAlertPrioritization('a1', 't1', 'R1', 1.0, [
            'replay_confidence_factor'    => 2.5,
            'recurrence_factor'           => 2.5,
            'escalation_frequency_factor' => 2.5,
        ]);
        $this->assertLessThanOrEqual(1.0, $score->final_priority_score);
    }

    public function test_prioritization_score_deterministic(): void
    {
        $s1 = $this->service->scoreAlertPrioritization('a1', 't1', 'R1', 0.75, [
            'replay_confidence_factor' => 1.2,
            'recurrence_factor'        => 1.1,
        ]);
        $s2 = $this->service->scoreAlertPrioritization('a2', 't1', 'R1', 0.75, [
            'replay_confidence_factor' => 1.2,
            'recurrence_factor'        => 1.1,
        ]);
        $this->assertEqualsWithDelta($s1->final_priority_score, $s2->final_priority_score, 0.001);
    }

    public function test_prioritization_score_is_append_only(): void
    {
        $score = $this->service->scoreAlertPrioritization('a1', 't1', 'R1', 0.75);
        $model = AlertPrioritizationScore::where('score_id', $score->score_id)->first();
        $this->expectException(LogicException::class);
        $model->priority_tier = 'low';
        $model->save();
    }

    // =========================================================================
    // False-positive tuning
    // =========================================================================

    public function test_fp_tuning_report_creates_record(): void
    {
        $r = $this->service->recordFpTuningReport('RULE_001', 't1', 'analyst-1', 'suppress', 0.15, [
            'suppression_scope'       => 'rule_id|user',
            'suppression_duration_days'=> 7,
        ]);
        $this->assertEquals('suppress', $r->tuning_action);
        $this->assertTrue($r->expiry_tracked);
        $this->assertTrue($r->is_advisory);
    }

    public function test_fp_tuning_rejects_invalid_action(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordFpTuningReport('RULE_001', 't1', 'analyst-1', 'delete_rule', 0.15);
    }

    public function test_fp_tuning_suppression_duration_exceeds_max_throws(): void
    {
        $this->expectException(\OverflowException::class);
        $this->service->recordFpTuningReport('RULE_001', 't1', 'analyst-1', 'suppress', 0.15, [
            'suppression_duration_days' => FalsePositiveTuningReport::MAX_SUPPRESSION_DAYS + 1,
        ]);
    }

    public function test_fp_tuning_at_max_suppression_days_succeeds(): void
    {
        $r = $this->service->recordFpTuningReport('RULE_001', 't1', 'analyst-1', 'suppress', 0.15, [
            'suppression_duration_days' => FalsePositiveTuningReport::MAX_SUPPRESSION_DAYS,
        ]);
        $this->assertEquals(FalsePositiveTuningReport::MAX_SUPPRESSION_DAYS, $r->suppression_duration_days);
    }

    public function test_fp_tuning_without_suppression_has_expiry_not_tracked(): void
    {
        $r = $this->service->recordFpTuningReport('RULE_001', 't1', 'analyst-1', 'tune_threshold', 0.10);
        $this->assertFalse($r->expiry_tracked);
    }

    public function test_fp_tuning_is_append_only(): void
    {
        $r = $this->service->recordFpTuningReport('RULE_001', 't1', 'analyst-1', 'add_allowlist', 0.05);
        $model = FalsePositiveTuningReport::where('report_id', $r->report_id)->first();
        $this->expectException(LogicException::class);
        $model->tuning_action = 'suppress';
        $model->save();
    }

    // =========================================================================
    // Analyst acknowledgment audit
    // =========================================================================

    public function test_acknowledgment_audit_creates_record(): void
    {
        $a = $this->service->recordAcknowledgment('analyst-1', 't1', 'alert-001', 'RULE_001', 'confirmed', 45.0);
        $this->assertEquals('confirmed', $a->acknowledgment_action);
        $this->assertEquals(45.0, $a->latency_seconds);
        $this->assertTrue($a->replay_consistent);
    }

    public function test_acknowledgment_rejects_invalid_action(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordAcknowledgment('analyst-1', 't1', 'alert-001', 'RULE_001', 'resolved_invalid', 10.0);
    }

    public function test_repeated_dismissal_flagged_after_threshold(): void
    {
        $a = $this->service->recordAcknowledgment('analyst-1', 't1', 'alert-001', 'RULE_001', 'dismissed', 5.0, [
            'dismissal_count' => 5,
        ]);
        $this->assertTrue($a->repeated_dismissal);
    }

    public function test_no_repeated_dismissal_for_single_dismiss(): void
    {
        $a = $this->service->recordAcknowledgment('analyst-1', 't1', 'alert-001', 'RULE_001', 'dismissed', 5.0, [
            'dismissal_count' => 1,
        ]);
        $this->assertFalse($a->repeated_dismissal);
    }

    public function test_acknowledgment_audit_uses_explicit_table(): void
    {
        $a = $this->service->recordAcknowledgment('analyst-1', 't1', 'alert-001', 'RULE_001', 'confirmed', 10.0);
        $this->assertDatabaseHas('analyst_acknowledgment_audit', ['audit_id' => $a->audit_id]);
    }

    public function test_acknowledgment_audit_is_append_only(): void
    {
        $a = $this->service->recordAcknowledgment('analyst-1', 't1', 'alert-001', 'RULE_001', 'escalated', 20.0);
        $model = AnalystAcknowledgmentAudit::where('audit_id', $a->audit_id)->first();
        $this->expectException(LogicException::class);
        $model->acknowledgment_action = 'dismissed';
        $model->save();
    }

    // =========================================================================
    // Escalation quality reviews
    // =========================================================================

    public function test_escalation_quality_high_verdict(): void
    {
        $r = $this->service->reviewEscalationQuality('esc-001', 't1', 'reviewer-1', 0.90, true, true);
        $this->assertEquals('high', $r->quality_tier);
        $this->assertEquals('valid', $r->verdict);
    }

    public function test_escalation_quality_noise_verdict(): void
    {
        $r = $this->service->reviewEscalationQuality('esc-002', 't1', 'reviewer-1', 0.10, false, false);
        $this->assertEquals('noise', $r->quality_tier);
        $this->assertEquals('noise', $r->verdict);
    }

    public function test_escalation_quality_score_is_bounded(): void
    {
        $r = $this->service->reviewEscalationQuality('esc-003', 't1', 'reviewer-1', 0.65, true, false);
        $this->assertGreaterThanOrEqual(0.0, $r->quality_score);
        $this->assertLessThanOrEqual(1.0, $r->quality_score);
    }

    public function test_escalation_quality_is_advisory(): void
    {
        $r = $this->service->reviewEscalationQuality('esc-004', 't1', 'reviewer-1', 0.75, true, true);
        $this->assertTrue($r->is_advisory);
    }

    public function test_escalation_quality_is_append_only(): void
    {
        $r = $this->service->reviewEscalationQuality('esc-005', 't1', 'reviewer-1', 0.80, true, true);
        $model = EscalationQualityReview::where('review_id', $r->review_id)->first();
        $this->expectException(LogicException::class);
        $model->verdict = 'noise';
        $model->save();
    }

    // =========================================================================
    // Investigation ergonomic views (mutable)
    // =========================================================================

    public function test_ergonomic_view_is_mutable(): void
    {
        $view = $this->service->createErgonomicView('inv-001', 'analyst-1', 't1');
        $view->update(['timeline_compressed' => true, 'chain_summarized' => true]);
        $fresh = $view->fresh();
        $this->assertTrue($fresh->timeline_compressed);
        $this->assertTrue($fresh->chain_summarized);
    }

    public function test_ergonomic_view_bounded_traversal_always_true(): void
    {
        $view = $this->service->createErgonomicView('inv-001', 'analyst-1', 't1');
        $this->assertTrue($view->bounded_traversal);
    }

    public function test_bookmark_view_increments_count(): void
    {
        $view = $this->service->createErgonomicView('inv-001', 'analyst-1', 't1');
        $bookmarked = $this->service->bookmarkView($view);
        $this->assertEquals('bookmarked', $bookmarked->status);
        $this->assertEquals(1, $bookmarked->bookmark_count);
    }

    public function test_ergonomic_view_is_advisory(): void
    {
        $view = $this->service->createErgonomicView('inv-001', 'analyst-1', 't1');
        $this->assertTrue($view->is_advisory);
    }

    // =========================================================================
    // Alert recurrence reports
    // =========================================================================

    public function test_alert_recurrence_creates_record(): void
    {
        $r = $this->service->recordAlertRecurrence('RULE_001', 't1', 10, 5);
        $this->assertEquals(10, $r->recurrence_count);
        $this->assertEquals(5, $r->window_hours);
        $this->assertTrue($r->suppression_candidate);
    }

    public function test_low_recurrence_not_suppression_candidate(): void
    {
        $r = $this->service->recordAlertRecurrence('RULE_001', 't1', 2, 24);
        $this->assertFalse($r->suppression_candidate);
    }

    public function test_recurrence_rate_is_calculated(): void
    {
        $r = $this->service->recordAlertRecurrence('RULE_001', 't1', 12, 6);
        $this->assertEqualsWithDelta(2.0, $r->recurrence_rate, 0.001);
    }

    public function test_alert_recurrence_is_append_only(): void
    {
        $r = $this->service->recordAlertRecurrence('RULE_001', 't1', 5, 24);
        $model = AlertRecurrenceReport::where('report_id', $r->report_id)->first();
        $this->expectException(LogicException::class);
        $model->suppression_candidate = true;
        $model->save();
    }

    // =========================================================================
    // Operational fatigue indicators
    // =========================================================================

    public function test_fatigue_detected_with_consecutive_dismissals(): void
    {
        $f = $this->service->recordFatigueIndicator('analyst-1', 't1', [
            'consecutive_dismissals' => AnalystOptimizationService::FATIGUE_CONSECUTIVE_DISMISSALS,
        ]);
        $this->assertTrue($f->fatigue_detected);
        $this->assertNotEquals('none', $f->fatigue_severity);
    }

    public function test_fatigue_detected_with_acceleration_rate(): void
    {
        $f = $this->service->recordFatigueIndicator('analyst-1', 't1', [
            'dismissal_acceleration_rate' => AnalystOptimizationService::FATIGUE_ACCELERATION_THRESHOLD,
        ]);
        $this->assertTrue($f->fatigue_detected);
    }

    public function test_no_fatigue_for_normal_analyst(): void
    {
        $f = $this->service->recordFatigueIndicator('analyst-1', 't1', [
            'consecutive_dismissals'      => 2,
            'dismissal_acceleration_rate' => 0.5,
        ]);
        $this->assertFalse($f->fatigue_detected);
        $this->assertEquals('none', $f->fatigue_severity);
    }

    public function test_high_fatigue_severity_for_extreme_values(): void
    {
        $f = $this->service->recordFatigueIndicator('analyst-1', 't1', [
            'consecutive_dismissals'      => 25,
            'dismissal_acceleration_rate' => 4.0,
        ]);
        $this->assertEquals('high', $f->fatigue_severity);
    }

    public function test_fatigue_indicator_is_advisory(): void
    {
        $f = $this->service->recordFatigueIndicator('analyst-1', 't1', []);
        $this->assertTrue($f->is_advisory);
    }

    public function test_fatigue_indicator_is_append_only(): void
    {
        $f = $this->service->recordFatigueIndicator('analyst-1', 't1', []);
        $model = OperationalFatigueIndicator::where('indicator_id', $f->indicator_id)->first();
        $this->expectException(LogicException::class);
        $model->fatigue_detected = true;
        $model->save();
    }

    // =========================================================================
    // Shift handoff validations
    // =========================================================================

    public function test_shift_handoff_creates_record(): void
    {
        $h = $this->service->recordShiftHandoff('outgoing-1', 'incoming-1', 't1', 'shift-001', [
            'open_investigations'  => 5,
            'pending_escalations'  => 2,
            'context_documented'   => true,
        ]);
        $this->assertEquals('outgoing-1', $h->outgoing_analyst_id);
        $this->assertEquals('incoming-1', $h->incoming_analyst_id);
        $this->assertTrue($h->context_documented);
        $this->assertTrue($h->continuity_preserved);
        $this->assertTrue($h->is_advisory);
    }

    public function test_shift_handoff_continuity_always_preserved(): void
    {
        $h = $this->service->recordShiftHandoff('a1', 'a2', 't1', 'shift-001');
        $this->assertTrue($h->continuity_preserved);
    }

    public function test_shift_handoff_is_append_only(): void
    {
        $h = $this->service->recordShiftHandoff('a1', 'a2', 't1', 'shift-001');
        $model = ShiftHandoffValidation::where('handoff_id', $h->handoff_id)->first();
        $this->expectException(LogicException::class);
        $model->continuity_preserved = false;
        $model->save();
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_returns_expected_keys(): void
    {
        $stats = $this->service->dashboardStats();
        $this->assertArrayHasKey('overloaded_analysts', $stats);
        $this->assertArrayHasKey('critical_alerts', $stats);
        $this->assertArrayHasKey('fp_tuning_reports', $stats);
        $this->assertArrayHasKey('repeated_dismissals', $stats);
        $this->assertArrayHasKey('escalation_noise', $stats);
        $this->assertArrayHasKey('recurrence_candidates', $stats);
        $this->assertArrayHasKey('fatigue_detected', $stats);
        $this->assertArrayHasKey('handoffs_validated', $stats);
    }

    // =========================================================================
    // ThreatHunting domain integration
    // =========================================================================

    public function test_threat_hunting_supported_domains_count(): void
    {
        $this->assertCount(181, app(ThreatHuntingService::class)->supportedDomains());
    }

    public function test_analyst_workload_snapshots_domain_supported(): void
    {
        $this->assertContains('analyst_workload_snapshots', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_alert_prioritization_scores_domain_supported(): void
    {
        $this->assertContains('alert_prioritization_scores', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_false_positive_tuning_reports_domain_supported(): void
    {
        $this->assertContains('false_positive_tuning_reports', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_escalation_quality_reviews_domain_supported(): void
    {
        $this->assertContains('escalation_quality_reviews', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_operational_fatigue_indicators_domain_supported(): void
    {
        $this->assertContains('operational_fatigue_indicators', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    // =========================================================================
    // Route access
    // =========================================================================

    public function test_analyst_opt_dashboard_requires_auth(): void
    {
        $this->get(route('analyst-opt.dashboard'))->assertRedirect();
    }

    public function test_analyst_opt_dashboard_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('analyst-opt.dashboard'))->assertOk();
    }

    public function test_analyst_opt_prioritization_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('analyst-opt.prioritization'))->assertOk();
    }

    public function test_analyst_opt_workload_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('analyst-opt.workload'))->assertOk();
    }

    public function test_analyst_opt_fatigue_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('analyst-opt.fatigue'))->assertOk();
    }

    // =========================================================================
    // Advisory notice in views
    // =========================================================================

    public function test_dashboard_view_contains_advisory_notice(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('analyst-opt.dashboard'))
            ->assertSee('advisory-only');
    }
}

