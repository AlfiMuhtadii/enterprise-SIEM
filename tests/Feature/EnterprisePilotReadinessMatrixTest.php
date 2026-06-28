<?php

namespace Tests\Feature;

use App\Models\PilotReadinessEvidenceLink;
use App\Models\PilotReadinessGateEvaluation;
use App\Models\PilotReadinessMatrixRun;
use App\Services\EnterprisePilotReadinessMatrixService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;
use Tests\Traits\AssertsAdvisoryOnlyConstraints;

class EnterprisePilotReadinessMatrixTest extends TestCase
{
    use RefreshDatabase;
    use AssertsAdvisoryOnlyConstraints;

    private EnterprisePilotReadinessMatrixService $svc;

    protected function getAdvisoryServiceClass(): string
    {
        return EnterprisePilotReadinessMatrixService::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(EnterprisePilotReadinessMatrixService::class);
    }

    // =========================================================================
    // Advisory-only & hard constraints (6 tests)
    // =========================================================================

    public function test_advisory_only_constant(): void
    {
        $this->assertTrue(EnterprisePilotReadinessMatrixService::ADVISORY_ONLY);
    }

    public function test_self_approve_blocked_constant(): void
    {
        $this->assertTrue(EnterprisePilotReadinessMatrixService::SELF_APPROVE_BLOCKED);
    }

    public function test_autonomous_promotion_constant_is_false(): void
    {
        $this->assertFalse(EnterprisePilotReadinessMatrixService::AUTONOMOUS_PROMOTION);
    }

    public function test_pass_threshold_is_point_eight(): void
    {
        $this->assertEquals(0.80, EnterprisePilotReadinessMatrixService::PASS_THRESHOLD);
    }

    public function test_max_gates_constant(): void
    {
        $this->assertEquals(20, EnterprisePilotReadinessMatrixService::MAX_GATES);
    }

    public function test_required_gate_ids_includes_soak_and_replay(): void
    {
        $ids = EnterprisePilotReadinessMatrixService::REQUIRED_GATE_IDS;
        $this->assertContains('soak_validation', $ids);
        $this->assertContains('replay_verification', $ids);
        $this->assertContains('tenant_isolation', $ids);
        $this->assertContains('rollback_readiness', $ids);
    }

    // =========================================================================
    // initiateMatrixRun (6 tests)
    // =========================================================================

    public function test_initiate_matrix_run_creates_record(): void
    {
        $run = $this->svc->initiateMatrixRun('tenant-1', 'operator-a');

        $this->assertInstanceOf(PilotReadinessMatrixRun::class, $run);
        $this->assertStringStartsWith('prm-', $run->matrix_run_id);
        $this->assertEquals('tenant-1', $run->tenant_id);
        $this->assertEquals('operator-a', $run->operator_id);
    }

    public function test_initiate_sets_is_advisory_true(): void
    {
        $run = $this->svc->initiateMatrixRun('tenant-1', 'op-1');
        $this->assertTrue($run->is_advisory);
    }

    public function test_initiate_sets_autonomous_promotion_false(): void
    {
        $run = $this->svc->initiateMatrixRun('tenant-1', 'op-1');
        $this->assertFalse($run->autonomous_promotion);
    }

    public function test_initiate_status_is_initiated(): void
    {
        $run = $this->svc->initiateMatrixRun('tenant-1', 'op-1');
        $this->assertEquals('initiated', $run->status);
    }

    public function test_initiate_with_pilot_scope(): void
    {
        $scope = ['domain' => 'identity-cloud', 'endpoints' => 10];
        $run   = $this->svc->initiateMatrixRun('tenant-1', 'op-1', $scope);

        $this->assertEquals($scope, $run->pilot_scope);
    }

    public function test_initiate_gates_start_at_zero(): void
    {
        $run = $this->svc->initiateMatrixRun('tenant-1', 'op-1');
        $this->assertEquals(0, $run->total_gates);
        $this->assertEquals(0, $run->gates_passed);
        $this->assertEquals(0, $run->matrix_score);
    }

    // =========================================================================
    // recordGateEvaluation (8 tests)
    // =========================================================================

    public function test_record_gate_evaluation_creates_record(): void
    {
        $run  = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $eval = $this->svc->recordGateEvaluation($run, 'soak_validation', 'technical', 'pass', 'Soak PASS');

        $this->assertInstanceOf(PilotReadinessGateEvaluation::class, $eval);
        $this->assertEquals('soak_validation', $eval->gate_id);
        $this->assertEquals('technical', $eval->dimension);
        $this->assertEquals('pass', $eval->status);
    }

    public function test_record_gate_evaluation_accepts_all_dimensions(): void
    {
        $run = $this->svc->initiateMatrixRun('t-1', 'op-1');
        foreach (EnterprisePilotReadinessMatrixService::DIMENSIONS as $dim) {
            $eval = $this->svc->recordGateEvaluation($run, "gate_{$dim}", $dim, 'pass');
            $this->assertEquals($dim, $eval->dimension);
        }
    }

    public function test_record_gate_evaluation_rejects_invalid_dimension(): void
    {
        $run = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->recordGateEvaluation($run, 'gate1', 'invalid_dim', 'pass');
    }

    public function test_record_gate_evaluation_rejects_invalid_status(): void
    {
        $run = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->recordGateEvaluation($run, 'gate1', 'technical', 'unknown');
    }

    public function test_record_gate_evaluation_stores_score(): void
    {
        $run  = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $eval = $this->svc->recordGateEvaluation($run, 'replay', 'technical', 'pass', '', 0.98);

        $this->assertEquals(0.98, $eval->score);
    }

    public function test_record_gate_evaluation_stores_evidence(): void
    {
        $run  = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $evidence = ['source' => 'PilotOnboardingRun', 'ref' => 'por-abc'];
        $eval = $this->svc->recordGateEvaluation($run, 'gate1', 'operational', 'warn', '', null, $evidence);

        $this->assertEquals($evidence, $eval->evidence);
    }

    public function test_record_gate_evaluation_enforces_max_gates(): void
    {
        $run = $this->svc->initiateMatrixRun('t-1', 'op-1');
        for ($i = 0; $i < EnterprisePilotReadinessMatrixService::MAX_GATES; $i++) {
            $this->svc->recordGateEvaluation($run, "gate_{$i}", 'technical', 'pass');
        }

        $this->expectException(\OverflowException::class);
        $this->svc->recordGateEvaluation($run, 'gate_overflow', 'technical', 'pass');
    }

    public function test_gate_evaluation_is_append_only(): void
    {
        $run  = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $eval = $this->svc->recordGateEvaluation($run, 'gate1', 'technical', 'pass');

        $this->expectException(LogicException::class);
        $eval->status = 'fail';
        $eval->save();
    }

    // =========================================================================
    // linkEvidence (4 tests)
    // =========================================================================

    public function test_link_evidence_creates_record(): void
    {
        $run  = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $eval = $this->svc->recordGateEvaluation($run, 'soak_validation', 'technical', 'pass');
        $link = $this->svc->linkEvidence($run, $eval, 'PilotOnboardingRun', 'por-123');

        $this->assertInstanceOf(PilotReadinessEvidenceLink::class, $link);
        $this->assertEquals('PilotOnboardingRun', $link->source_type);
        $this->assertEquals('por-123', $link->source_id);
    }

    public function test_link_evidence_stores_evidence_data(): void
    {
        $run  = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $eval = $this->svc->recordGateEvaluation($run, 'gate1', 'security', 'pass');
        $data = ['soak_pass' => true, 'score' => 0.97];
        $link = $this->svc->linkEvidence($run, $eval, 'SoakValidationRun', 'svr-001', $data);

        $this->assertEquals($data, $link->evidence_data);
    }

    public function test_evidence_link_is_append_only(): void
    {
        $run  = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $eval = $this->svc->recordGateEvaluation($run, 'gate1', 'technical', 'pass');
        $link = $this->svc->linkEvidence($run, $eval, 'Type', 'id-1');

        $this->expectException(LogicException::class);
        $link->source_id = 'modified';
        $link->save();
    }

    public function test_evidence_link_references_correct_run(): void
    {
        $run  = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $eval = $this->svc->recordGateEvaluation($run, 'gate1', 'easm', 'warn');
        $link = $this->svc->linkEvidence($run, $eval, 'EasmAssetRiskScore', 'score-42');

        $this->assertEquals($run->matrix_run_id, $link->matrix_run_id);
        $this->assertEquals($eval->id, $link->gate_eval_id);
    }

    // =========================================================================
    // computeMatrixScore (7 tests)
    // =========================================================================

    public function test_compute_score_all_pass(): void
    {
        $run = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $this->svc->recordGateEvaluation($run, 'soak_validation', 'technical', 'pass');
        $this->svc->recordGateEvaluation($run, 'replay_verification', 'technical', 'pass');
        $this->svc->recordGateEvaluation($run, 'tenant_isolation', 'security', 'pass');
        $this->svc->recordGateEvaluation($run, 'rollback_readiness', 'operational', 'pass');

        $result = $this->svc->computeMatrixScore($run);
        $this->assertEquals(1.0, $result['score']);
        $this->assertTrue($result['required_gates_pass']);
        $this->assertEquals('PASS', $result['overall_status']);
    }

    public function test_compute_score_required_gate_fail_blocks_pass(): void
    {
        $run = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $this->svc->recordGateEvaluation($run, 'soak_validation', 'technical', 'fail', 'Soak failed');
        $this->svc->recordGateEvaluation($run, 'replay_verification', 'technical', 'pass');

        $result = $this->svc->computeMatrixScore($run);
        $this->assertFalse($result['required_gates_pass']);
        $this->assertEquals('FAIL', $result['overall_status']);
    }

    public function test_compute_score_below_threshold_is_fail(): void
    {
        $run = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $this->svc->recordGateEvaluation($run, 'gate1', 'technical', 'pass');
        $this->svc->recordGateEvaluation($run, 'gate2', 'technical', 'fail');
        $this->svc->recordGateEvaluation($run, 'gate3', 'technical', 'fail');

        $result = $this->svc->computeMatrixScore($run);
        $this->assertLessThan(0.80, $result['score']);
        $this->assertEquals('FAIL', $result['overall_status']);
    }

    public function test_compute_score_skipped_gates_excluded_from_ratio(): void
    {
        $run = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $this->svc->recordGateEvaluation($run, 'gate1', 'technical', 'pass');
        $this->svc->recordGateEvaluation($run, 'gate2', 'easm', 'skip');
        $this->svc->recordGateEvaluation($run, 'gate3', 'technical', 'pass');

        $result = $this->svc->computeMatrixScore($run);
        $this->assertEquals(1.0, $result['score']);
        $this->assertEquals(1, $result['skipped']);
    }

    public function test_compute_score_warn_counts_in_total_but_not_failed(): void
    {
        $run = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $this->svc->recordGateEvaluation($run, 'gate1', 'technical', 'pass');
        $this->svc->recordGateEvaluation($run, 'gate2', 'technical', 'warn');
        $this->svc->recordGateEvaluation($run, 'gate3', 'technical', 'pass');
        $this->svc->recordGateEvaluation($run, 'gate4', 'technical', 'pass');
        $this->svc->recordGateEvaluation($run, 'gate5', 'technical', 'pass');

        $result = $this->svc->computeMatrixScore($run);
        $this->assertEquals(1, $result['warned']);
        $this->assertEquals(0, $result['failed']);
    }

    public function test_compute_score_at_exactly_threshold_is_pass(): void
    {
        $run = $this->svc->initiateMatrixRun('t-1', 'op-1');
        for ($i = 0; $i < 4; $i++) {
            $this->svc->recordGateEvaluation($run, "p{$i}", 'technical', 'pass');
        }
        $this->svc->recordGateEvaluation($run, 'f1', 'technical', 'fail');

        $result = $this->svc->computeMatrixScore($run);
        $this->assertEquals(0.8, round($result['score'], 4));
        $this->assertEquals('PASS', $result['overall_status']);
    }

    public function test_compute_score_empty_run_returns_zero(): void
    {
        $run    = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $result = $this->svc->computeMatrixScore($run);
        $this->assertEquals(0.0, $result['score']);
        $this->assertEquals(0, $result['total']);
    }

    // =========================================================================
    // finalizeMatrixRun (5 tests)
    // =========================================================================

    public function test_finalize_blocks_self_approval(): void
    {
        $run = $this->svc->initiateMatrixRun('t-1', 'operator-alice');

        $this->expectException(\LogicException::class);
        $this->svc->finalizeMatrixRun($run, 'operator-alice');
    }

    public function test_finalize_updates_status_to_passed(): void
    {
        $run = $this->svc->initiateMatrixRun('t-1', 'op-alice');
        $this->svc->recordGateEvaluation($run, 'soak_validation', 'technical', 'pass');
        $this->svc->recordGateEvaluation($run, 'replay_verification', 'technical', 'pass');
        $this->svc->recordGateEvaluation($run, 'tenant_isolation', 'security', 'pass');
        $this->svc->recordGateEvaluation($run, 'rollback_readiness', 'operational', 'pass');

        $finalized = $this->svc->finalizeMatrixRun($run, 'reviewer-bob');
        $this->assertEquals('passed', $finalized->status);
        $this->assertNotNull($finalized->finalized_at);
    }

    public function test_finalize_updates_status_to_failed_when_below_threshold(): void
    {
        $run = $this->svc->initiateMatrixRun('t-1', 'op-alice');
        $this->svc->recordGateEvaluation($run, 'gate1', 'technical', 'fail');
        $this->svc->recordGateEvaluation($run, 'gate2', 'technical', 'fail');

        $finalized = $this->svc->finalizeMatrixRun($run, 'reviewer-bob');
        $this->assertEquals('failed', $finalized->status);
    }

    public function test_finalize_stores_approved_by(): void
    {
        $run = $this->svc->initiateMatrixRun('t-1', 'op-alice');
        $this->svc->recordGateEvaluation($run, 'soak_validation', 'technical', 'pass');
        $this->svc->recordGateEvaluation($run, 'replay_verification', 'technical', 'pass');
        $this->svc->recordGateEvaluation($run, 'tenant_isolation', 'security', 'pass');
        $this->svc->recordGateEvaluation($run, 'rollback_readiness', 'operational', 'pass');

        $finalized = $this->svc->finalizeMatrixRun($run, 'reviewer-bob', 'All gates reviewed.');
        $this->assertEquals('reviewer-bob', $finalized->approved_by);
        $this->assertEquals('All gates reviewed.', $finalized->reviewer_notes);
    }

    public function test_finalize_never_sets_autonomous_promotion(): void
    {
        $run = $this->svc->initiateMatrixRun('t-1', 'op-alice');
        $this->svc->recordGateEvaluation($run, 'soak_validation', 'technical', 'pass');
        $this->svc->recordGateEvaluation($run, 'replay_verification', 'technical', 'pass');
        $this->svc->recordGateEvaluation($run, 'tenant_isolation', 'security', 'pass');
        $this->svc->recordGateEvaluation($run, 'rollback_readiness', 'operational', 'pass');

        $finalized = $this->svc->finalizeMatrixRun($run, 'reviewer-bob');
        $this->assertFalse($finalized->autonomous_promotion);
    }

    // =========================================================================
    // generateMatrixReport (4 tests)
    // =========================================================================

    public function test_generate_matrix_report_returns_array(): void
    {
        $run    = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $report = $this->svc->generateMatrixReport($run);

        $this->assertIsArray($report);
        $this->assertArrayHasKey('matrix_run_id', $report);
        $this->assertArrayHasKey('by_dimension', $report);
        $this->assertArrayHasKey('required_gates', $report);
    }

    public function test_generate_report_is_advisory_true(): void
    {
        $run    = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $report = $this->svc->generateMatrixReport($run);

        $this->assertTrue($report['is_advisory']);
        $this->assertFalse($report['autonomous_promotion']);
    }

    public function test_generate_report_groups_by_dimension(): void
    {
        $run = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $this->svc->recordGateEvaluation($run, 'soak_validation', 'technical', 'pass');
        $this->svc->recordGateEvaluation($run, 'tenant_isolation', 'security', 'pass');

        $report = $this->svc->generateMatrixReport($run);
        $this->assertCount(1, $report['by_dimension']['technical']);
        $this->assertCount(1, $report['by_dimension']['security']);
        $this->assertCount(0, $report['by_dimension']['easm']);
    }

    public function test_generate_report_includes_required_gates(): void
    {
        $run    = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $report = $this->svc->generateMatrixReport($run);

        $this->assertContains('soak_validation', $report['required_gates']);
        $this->assertContains('replay_verification', $report['required_gates']);
    }

    // =========================================================================
    // Model immutability (4 tests)
    // =========================================================================

    public function test_matrix_run_delete_is_forbidden(): void
    {
        $run = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $this->expectException(LogicException::class);
        $run->delete();
    }

    public function test_gate_evaluation_delete_is_forbidden(): void
    {
        $run  = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $eval = $this->svc->recordGateEvaluation($run, 'g1', 'technical', 'pass');
        $this->expectException(LogicException::class);
        $eval->delete();
    }

    public function test_evidence_link_delete_is_forbidden(): void
    {
        $run  = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $eval = $this->svc->recordGateEvaluation($run, 'g1', 'technical', 'pass');
        $link = $this->svc->linkEvidence($run, $eval, 'T', 'id');
        $this->expectException(LogicException::class);
        $link->delete();
    }

    public function test_matrix_run_allows_status_update(): void
    {
        $run = $this->svc->initiateMatrixRun('t-1', 'op-1');
        $run->status = 'evaluating';
        $run->saveQuietly();
        $this->assertEquals('evaluating', $run->fresh()->status);
    }

    // =========================================================================
    // ThreatHunting integration (3 tests)
    // =========================================================================

    public function test_threat_hunting_includes_matrix_runs_domain(): void
    {
        $domains = ThreatHuntingService::SUPPORTED_DOMAINS;
        $this->assertContains('pilot_readiness_matrix_runs', $domains);
    }

    public function test_threat_hunting_includes_gate_evaluations_domain(): void
    {
        $domains = ThreatHuntingService::SUPPORTED_DOMAINS;
        $this->assertContains('pilot_readiness_gate_evaluations', $domains);
    }

    public function test_threat_hunting_includes_evidence_links_domain(): void
    {
        $domains = ThreatHuntingService::SUPPORTED_DOMAINS;
        $this->assertContains('pilot_readiness_evidence_links', $domains);
    }

    // =========================================================================
    // Domain count (1 test)
    // =========================================================================

    public function test_threat_hunting_supported_domains_count(): void
    {
        $this->assertCount(172, ThreatHuntingService::SUPPORTED_DOMAINS);
    }
}
