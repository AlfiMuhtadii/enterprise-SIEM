<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\FinalXdrCertificationService;
use App\Models\XdrReadinessCertification;
use App\Models\ProductionAcceptanceGate;
use App\Models\OperationalLimitationReport;
use App\Models\ExecutiveReadinessReport;
use App\Models\TechnicalReadinessReport;
use App\Models\ProductionRiskRegister;
use App\Models\GoLiveValidationRun;
use App\Models\DeploymentAcceptanceAudit;
use App\Models\FinalXdrCertificationAudit;
use App\Services\ThreatHuntingService;

class FinalXdrCertificationTest extends TestCase
{
    use RefreshDatabase;

    private FinalXdrCertificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FinalXdrCertificationService();
    }

    // =========================================================================
    // Hard constraint checks â€” no autonomous approval, self-approve blocked
    // =========================================================================

    public function test_service_constants_are_correct(): void
    {
        $this->assertTrue(FinalXdrCertificationService::ADVISORY_ONLY);
        $this->assertTrue(FinalXdrCertificationService::SELF_APPROVE_BLOCKED);
        $this->assertEquals(10.0, FinalXdrCertificationService::MAX_RISK_SCORE);
        $this->assertEquals(0.80, FinalXdrCertificationService::READINESS_PASS_THRESHOLD);
        $this->assertEquals(0, FinalXdrCertificationService::MAX_OPEN_CRITICAL_RISKS);
    }

    public function test_initiate_certification_never_sets_autonomous_approval(): void
    {
        $cert = $this->service->initiateCertification('initial');
        $this->assertFalse($cert->autonomous_approval);
        $this->assertTrue($cert->advisory_only);
    }

    public function test_evaluate_gate_never_allows_autonomous_waiver(): void
    {
        $gate = $this->service->evaluateGate('soak_validation', ['passed' => true, 'score' => 1.0]);
        $this->assertFalse($gate->autonomous_waiver);
        $this->assertTrue($gate->self_approve_blocked);
    }

    public function test_register_risk_never_sets_autonomous_mitigation(): void
    {
        $risk = $this->service->registerRisk('replay_failure', 'high', 'Test risk');
        $this->assertFalse($risk->autonomous_mitigation);
        $this->assertTrue($risk->is_advisory);
    }

    public function test_run_go_live_validation_never_sets_autonomous_go_live(): void
    {
        $run = $this->service->runGoLiveValidation('pre_cutover', [
            'soak_requirement_met' => true,
            'gate_requirement_met' => true,
            'risk_acceptable'      => true,
            'rollback_ready'       => true,
        ]);
        $this->assertFalse($run->autonomous_go_live);
        $this->assertTrue($run->replay_safe);
    }

    public function test_generate_executive_report_never_sets_autonomous_approval(): void
    {
        $report = $this->service->generateExecutiveReport('identity_cloud', 5, 5, 0, 0);
        $this->assertFalse($report->autonomous_approval);
        $this->assertTrue($report->self_approve_blocked);
        $this->assertTrue($report->is_advisory);
    }

    public function test_generate_technical_report_is_always_advisory(): void
    {
        $report = $this->service->generateTechnicalReport('correlation_engine', [
            'soak_passed'               => true,
            'replay_integrity_verified' => true,
            'contract_compliant'        => true,
            'rule_registry_valid'       => true,
            'fleet_simulation_passed'   => true,
        ]);
        $this->assertTrue($report->is_advisory);
    }

    public function test_audit_records_never_have_autonomous_actions(): void
    {
        $this->service->initiateCertification('initial');
        $audits = FinalXdrCertificationAudit::all();
        foreach ($audits as $audit) {
            $this->assertFalse($audit->autonomous_action_taken);
            $this->assertFalse($audit->destructive_action_taken);
            $this->assertTrue($audit->replay_safe);
        }
    }

    public function test_acceptance_audit_self_approve_always_blocked(): void
    {
        $this->service->evaluateGate('rule_registry', ['passed' => true, 'score' => 1.0]);
        $audits = DeploymentAcceptanceAudit::all();
        foreach ($audits as $audit) {
            $this->assertTrue($audit->self_approve_blocked);
            $this->assertFalse($audit->autonomous_action_taken);
            $this->assertFalse($audit->destructive_action_taken);
        }
    }

    // =========================================================================
    // Certification initiation & state transitions
    // =========================================================================

    public function test_initiate_certification_creates_pending_record(): void
    {
        $cert = $this->service->initiateCertification('initial', ['soak_validated' => true]);
        $this->assertEquals('pending', $cert->certification_state);
        $this->assertStringStartsWith('cert-', $cert->certification_id);
        $this->assertTrue($cert->soak_validated);
        $this->assertFalse($cert->all_gates_passed);
        $this->assertEquals(0.0, $cert->readiness_score);
    }

    public function test_initiate_certification_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->initiateCertification('invalid_type');
    }

    public function test_all_certification_types_are_accepted(): void
    {
        foreach (XdrReadinessCertification::CERTIFICATION_TYPES as $type) {
            $cert = $this->service->initiateCertification($type);
            $this->assertEquals($type, $cert->certification_type);
        }
    }

    public function test_update_certification_state_computes_gate_pass_rate(): void
    {
        $cert = $this->service->initiateCertification('initial');
        $updated = $this->service->updateCertificationState($cert, 'passed', 0.95, 5, 5);
        $this->assertEquals(1.0, $updated->gate_pass_rate);
        $this->assertTrue($updated->all_gates_passed);
        $this->assertEquals(0.95, $updated->readiness_score);
    }

    public function test_update_certification_state_partial_pass(): void
    {
        $cert = $this->service->initiateCertification('renewal');
        $updated = $this->service->updateCertificationState($cert, 'in_progress', 0.6, 3, 5);
        $this->assertEquals(0.6, $updated->gate_pass_rate);
        $this->assertFalse($updated->all_gates_passed);
    }

    public function test_update_certification_state_rejects_invalid_state(): void
    {
        $cert = $this->service->initiateCertification('initial');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateCertificationState($cert, 'invalid_state', 0.5, 2, 5);
    }

    public function test_readiness_score_clamped_to_max_1(): void
    {
        $cert = $this->service->initiateCertification('initial');
        $updated = $this->service->updateCertificationState($cert, 'passed', 99.0, 5, 5);
        $this->assertLessThanOrEqual(1.0, $updated->readiness_score);
    }

    // =========================================================================
    // Production acceptance gates
    // =========================================================================

    public function test_evaluate_gate_passed_state(): void
    {
        $gate = $this->service->evaluateGate('soak_validation', ['passed' => true, 'score' => 1.0]);
        $this->assertEquals('passed', $gate->gate_state);
        $this->assertTrue($gate->gate_passed);
    }

    public function test_evaluate_gate_failed_state(): void
    {
        $gate = $this->service->evaluateGate('replay_integrity', ['passed' => false, 'score' => 0.4]);
        $this->assertEquals('failed', $gate->gate_state);
        $this->assertFalse($gate->gate_passed);
    }

    public function test_evaluate_gate_rejects_invalid_gate_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->evaluateGate('invalid_gate', ['passed' => true]);
    }

    public function test_all_gate_names_are_accepted(): void
    {
        foreach (ProductionAcceptanceGate::GATE_NAMES as $name) {
            $gate = $this->service->evaluateGate($name, ['passed' => true, 'score' => 1.0]);
            $this->assertEquals($name, $gate->gate_name);
        }
    }

    public function test_gate_score_clamped_to_1(): void
    {
        $gate = $this->service->evaluateGate('contract_compliance', ['passed' => true, 'score' => 999.0]);
        $this->assertLessThanOrEqual(1.0, $gate->gate_score);
    }

    // =========================================================================
    // Operational limitation reports
    // =========================================================================

    public function test_record_limitation_creates_advisory_record(): void
    {
        $report = $this->service->recordLimitation(
            'advisory_only', 'info', 'Platform is advisory-only until soak PASS'
        );
        $this->assertTrue($report->is_advisory);
        $this->assertStringStartsWith('lim-', $report->limitation_report_id);
    }

    public function test_record_limitation_rejects_invalid_category(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordLimitation('bad_category', 'info', 'test');
    }

    public function test_record_limitation_rejects_invalid_severity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordLimitation('advisory_only', 'extreme', 'test');
    }

    public function test_cutover_blocked_flag_is_set(): void
    {
        $report = $this->service->recordLimitation(
            'soak_required', 'high', 'Soak not completed',
            ['cutover_blocked' => true]
        );
        $this->assertTrue($report->cutover_blocked);
    }

    // =========================================================================
    // Risk register
    // =========================================================================

    public function test_register_risk_with_critical_severity_sets_cutover_blocker(): void
    {
        $risk = $this->service->registerRisk(
            'soak_skipped', 'critical', 'Soak was skipped'
        );
        $this->assertTrue($risk->cutover_blocker);
    }

    public function test_register_risk_with_low_severity_does_not_block(): void
    {
        $risk = $this->service->registerRisk(
            'capacity_overrun', 'low', 'Minor capacity pressure'
        );
        $this->assertFalse($risk->cutover_blocker);
    }

    public function test_risk_score_clamped_to_max(): void
    {
        $risk = $this->service->registerRisk(
            'replay_failure', 'high', 'Replay failed',
            ['risk_score' => 999.0]
        );
        $this->assertLessThanOrEqual(FinalXdrCertificationService::MAX_RISK_SCORE, $risk->risk_score);
    }

    public function test_register_risk_rejects_invalid_category(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->registerRisk('invalid_category', 'low', 'test');
    }

    public function test_register_risk_rejects_invalid_severity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->registerRisk('replay_failure', 'extreme', 'test');
    }

    // =========================================================================
    // Go-live validation
    // =========================================================================

    public function test_go_live_passed_when_all_checks_pass(): void
    {
        $run = $this->service->runGoLiveValidation('pre_cutover', [
            'soak_requirement_met' => true,
            'gate_requirement_met' => true,
            'risk_acceptable'      => true,
            'rollback_ready'       => true,
        ]);
        $this->assertEquals('passed', $run->validation_state);
        $this->assertEquals(1.0, $run->validation_score);
    }

    public function test_go_live_failed_when_checks_insufficient(): void
    {
        $run = $this->service->runGoLiveValidation('pre_cutover', [
            'soak_requirement_met' => false,
            'gate_requirement_met' => false,
            'risk_acceptable'      => false,
            'rollback_ready'       => true,
        ]);
        $this->assertEquals('failed', $run->validation_state);
    }

    public function test_go_live_rejects_invalid_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->runGoLiveValidation('bad_scope', []);
    }

    // =========================================================================
    // Executive readiness reports
    // =========================================================================

    public function test_executive_report_approved_when_score_high_no_risks(): void
    {
        $report = $this->service->generateExecutiveReport('identity_cloud', 5, 5, 0, 0);
        $this->assertEquals('approved', $report->go_live_recommendation);
    }

    public function test_executive_report_blocked_when_high_risks_exist(): void
    {
        $report = $this->service->generateExecutiveReport('full_platform', 5, 5, 0, 2);
        $this->assertEquals('blocked', $report->go_live_recommendation);
    }

    public function test_executive_report_conditional_when_limits_open(): void
    {
        $report = $this->service->generateExecutiveReport('endpoint_shadow', 5, 5, 3, 0);
        $this->assertEquals('conditional', $report->go_live_recommendation);
    }

    public function test_executive_report_deferred_when_score_low(): void
    {
        $report = $this->service->generateExecutiveReport('network_shadow', 2, 5, 0, 0);
        $this->assertEquals('deferred', $report->go_live_recommendation);
    }

    public function test_executive_report_rejects_invalid_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->generateExecutiveReport('invalid_scope', 3, 5, 0, 0);
    }

    // =========================================================================
    // Technical readiness reports
    // =========================================================================

    public function test_technical_report_full_pass_score_is_1(): void
    {
        $report = $this->service->generateTechnicalReport('correlation_engine', [
            'soak_passed'               => true,
            'replay_integrity_verified' => true,
            'contract_compliant'        => true,
            'rule_registry_valid'       => true,
            'fleet_simulation_passed'   => true,
        ]);
        $this->assertEquals(1.0, $report->domain_readiness_score);
    }

    public function test_technical_report_partial_pass_score(): void
    {
        $report = $this->service->generateTechnicalReport('endpoint_agent', [
            'soak_passed'               => true,
            'replay_integrity_verified' => true,
            'contract_compliant'        => false,
            'rule_registry_valid'       => false,
            'fleet_simulation_passed'   => false,
        ]);
        $this->assertEquals(0.4, $report->domain_readiness_score);
    }

    public function test_technical_report_rejects_invalid_domain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->generateTechnicalReport('invalid_domain', []);
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_returns_correct_structure(): void
    {
        $stats = $this->service->dashboardStats();
        $this->assertArrayHasKey('total_certifications', $stats);
        $this->assertArrayHasKey('passed_certifications', $stats);
        $this->assertArrayHasKey('total_gates', $stats);
        $this->assertArrayHasKey('gates_passed', $stats);
        $this->assertArrayHasKey('open_limitations', $stats);
        $this->assertArrayHasKey('cutover_blocking_limits', $stats);
        $this->assertArrayHasKey('open_risks', $stats);
        $this->assertArrayHasKey('critical_risks', $stats);
        $this->assertArrayHasKey('go_live_validations', $stats);
        $this->assertArrayHasKey('go_live_passed', $stats);
    }

    public function test_dashboard_stats_counts_are_accurate(): void
    {
        $this->service->initiateCertification('initial');
        $this->service->evaluateGate('soak_validation', ['passed' => true, 'score' => 1.0]);
        $stats = $this->service->dashboardStats();
        $this->assertEquals(1, $stats['total_certifications']);
        $this->assertEquals(1, $stats['total_gates']);
        $this->assertEquals(1, $stats['gates_passed']);
    }

    // =========================================================================
    // Append-only integrity â€” no delete/update on any table
    // =========================================================================

    public function test_xdr_readiness_certifications_are_append_only(): void
    {
        $this->service->initiateCertification('initial');
        $this->service->initiateCertification('renewal');
        $this->assertEquals(2, XdrReadinessCertification::count());
    }

    public function test_production_acceptance_gates_are_append_only(): void
    {
        $this->service->evaluateGate('fleet_simulation', ['passed' => true, 'score' => 1.0]);
        $this->assertEquals(1, ProductionAcceptanceGate::count());
    }

    public function test_operational_limitation_reports_are_append_only(): void
    {
        $this->service->recordLimitation('advisory_only', 'info', 'Test limitation');
        $this->assertEquals(1, OperationalLimitationReport::count());
    }

    public function test_production_risk_register_is_append_only(): void
    {
        $this->service->registerRisk('contract_drift', 'medium', 'Contract drifted');
        $this->assertEquals(1, ProductionRiskRegister::count());
    }

    public function test_go_live_validation_runs_are_append_only(): void
    {
        $this->service->runGoLiveValidation('domain_specific', []);
        $this->assertEquals(1, GoLiveValidationRun::count());
    }

    public function test_final_xdr_cert_audit_are_append_only(): void
    {
        $this->service->initiateCertification('initial');
        $count = FinalXdrCertificationAudit::count();
        $this->assertGreaterThan(0, $count);
    }

    public function test_deployment_acceptance_audit_are_append_only(): void
    {
        $this->service->evaluateGate('rule_registry', ['passed' => true, 'score' => 1.0]);
        $count = DeploymentAcceptanceAudit::count();
        $this->assertGreaterThan(0, $count);
    }

    // =========================================================================
    // ThreatHuntingService â€” domain count
    // =========================================================================

    public function test_threat_hunting_service_has_correct_domain_count(): void
    {
        $domains = ThreatHuntingService::SUPPORTED_DOMAINS;
        $this->assertCount(161, $domains);
    }

    public function test_xdr_certification_domains_registered_in_threat_hunting(): void
    {
        $domains = ThreatHuntingService::SUPPORTED_DOMAINS;
        $this->assertContains('xdr_readiness_certifications', $domains);
        $this->assertContains('production_acceptance_gates', $domains);
        $this->assertContains('operational_limitation_reports', $domains);
        $this->assertContains('production_risk_register', $domains);
        $this->assertContains('go_live_validation_runs', $domains);
    }

    // =========================================================================
    // Routes â€” require auth
    // =========================================================================

    public function test_xdr_cert_dashboard_requires_auth(): void
    {
        $response = $this->get('/xdr-certification');
        $response->assertRedirect('/login');
    }

    public function test_xdr_cert_certifications_requires_auth(): void
    {
        $response = $this->get('/xdr-certification/certifications');
        $response->assertRedirect('/login');
    }

    public function test_xdr_cert_gates_requires_auth(): void
    {
        $response = $this->get('/xdr-certification/gates');
        $response->assertRedirect('/login');
    }

    public function test_xdr_cert_limitations_requires_auth(): void
    {
        $response = $this->get('/xdr-certification/limitations');
        $response->assertRedirect('/login');
    }

    public function test_xdr_cert_risks_requires_auth(): void
    {
        $response = $this->get('/xdr-certification/risks');
        $response->assertRedirect('/login');
    }

    public function test_xdr_cert_executive_requires_auth(): void
    {
        $response = $this->get('/xdr-certification/executive');
        $response->assertRedirect('/login');
    }

    public function test_xdr_cert_technical_requires_auth(): void
    {
        $response = $this->get('/xdr-certification/technical');
        $response->assertRedirect('/login');
    }

    public function test_xdr_cert_go_live_requires_auth(): void
    {
        $response = $this->get('/xdr-certification/go-live');
        $response->assertRedirect('/login');
    }

    public function test_xdr_cert_audit_requires_auth(): void
    {
        $response = $this->get('/xdr-certification/audit');
        $response->assertRedirect('/login');
    }
}

