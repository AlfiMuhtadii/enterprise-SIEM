<?php

namespace App\Services;

use App\Models\XdrReadinessCertification;
use App\Models\ProductionAcceptanceGate;
use App\Models\OperationalLimitationReport;
use App\Models\ExecutiveReadinessReport;
use App\Models\TechnicalReadinessReport;
use App\Models\ProductionRiskRegister;
use App\Models\GoLiveValidationRun;
use App\Models\DeploymentAcceptanceAudit;
use App\Models\FinalXdrCertificationAudit;
use Illuminate\Support\Str;

class FinalXdrCertificationService
{
    public const ADVISORY_ONLY          = true;
    public const SELF_APPROVE_BLOCKED   = true;
    public const MAX_RISK_SCORE         = 10.0;
    public const READINESS_PASS_THRESHOLD = 0.80;
    public const MAX_OPEN_CRITICAL_RISKS  = 0;

    // =========================================================================
    // XDR readiness certification
    // =========================================================================

    public function initiateCertification(string $certType, array $params = []): XdrReadinessCertification
    {
        if (!in_array($certType, XdrReadinessCertification::CERTIFICATION_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid certification type: {$certType}");
        }

        $cert = XdrReadinessCertification::create([
            'certification_id'       => 'cert-' . Str::uuid(),
            'certification_type'     => $certType,
            'certification_state'    => 'pending',
            'readiness_score'        => 0.0,
            'gate_pass_rate'         => 0.0,
            'all_gates_passed'       => false,
            'soak_validated'         => (bool) ($params['soak_validated'] ?? false),
            'replay_verified'        => (bool) ($params['replay_verified'] ?? false),
            'advisory_only'          => true,
            'autonomous_approval'    => false,
            'certification_evidence' => $params['evidence'] ?? null,
        ]);

        $this->recordCertAudit('certification_initiated', 'operator', $cert->certification_id);

        return $cert;
    }

    public function updateCertificationState(
        XdrReadinessCertification $cert,
        string $newState,
        float $readinessScore,
        int $gatesPassed,
        int $gatesTotal
    ): XdrReadinessCertification {
        if (!in_array($newState, XdrReadinessCertification::CERTIFICATION_STATES, true)) {
            throw new \InvalidArgumentException("Invalid certification state: {$newState}");
        }

        $readinessScore = min((float) $readinessScore, 1.0);
        $gatePassRate   = $gatesTotal > 0 ? ($gatesPassed / $gatesTotal) : 0.0;
        $allPassed      = ($gatesPassed === $gatesTotal && $gatesTotal > 0);

        $cert->update([
            'certification_state' => $newState,
            'readiness_score'     => $readinessScore,
            'gate_pass_rate'      => $gatePassRate,
            'all_gates_passed'    => $allPassed,
            'autonomous_approval' => false,
        ]);

        $eventType = $newState === 'passed' ? 'gate_passed' : 'gate_failed';
        $this->recordCertAudit($eventType, 'operator', $cert->certification_id);

        return $cert->fresh();
    }

    // =========================================================================
    // Production acceptance gates
    // =========================================================================

    public function evaluateGate(string $gateName, array $gateChecks, ?string $certId = null): ProductionAcceptanceGate
    {
        if (!in_array($gateName, ProductionAcceptanceGate::GATE_NAMES, true)) {
            throw new \InvalidArgumentException("Invalid gate name: {$gateName}");
        }

        $passed = (bool) ($gateChecks['passed'] ?? false);
        $score  = min((float) ($gateChecks['score'] ?? 0.0), 1.0);
        $state  = $passed ? 'passed' : 'failed';

        $gate = ProductionAcceptanceGate::create([
            'gate_run_id'         => 'gate-' . Str::uuid(),
            'gate_name'           => $gateName,
            'gate_state'          => $state,
            'gate_score'          => $score,
            'gate_passed'         => $passed,
            'self_approve_blocked'=> true,
            'autonomous_waiver'   => false,
            'replay_safe'         => true,
            'certification_id'    => $certId,
            'gate_evidence'       => $gateChecks['evidence'] ?? null,
        ]);

        $this->recordAcceptanceAudit('gate_evaluated', 'system', "gate:{$gateName}");

        return $gate;
    }

    // =========================================================================
    // Operational limitation reports
    // =========================================================================

    public function recordLimitation(
        string $category,
        string $severity,
        string $description,
        array  $params = []
    ): OperationalLimitationReport {
        if (!in_array($category, OperationalLimitationReport::LIMITATION_CATEGORIES, true)) {
            throw new \InvalidArgumentException("Invalid limitation category: {$category}");
        }

        if (!in_array($severity, OperationalLimitationReport::LIMITATION_SEVERITIES, true)) {
            throw new \InvalidArgumentException("Invalid limitation severity: {$severity}");
        }

        $report = OperationalLimitationReport::create([
            'limitation_report_id'  => 'lim-' . Str::uuid(),
            'limitation_category'   => $category,
            'limitation_severity'   => $severity,
            'limitation_description'=> $description,
            'affected_domain'       => $params['affected_domain'] ?? null,
            'cutover_blocked'       => (bool) ($params['cutover_blocked'] ?? false),
            'mitigation_available'  => (bool) ($params['mitigation_available'] ?? false),
            'is_advisory'           => true,
            'limitation_evidence'   => $params['evidence'] ?? null,
        ]);

        $this->recordAcceptanceAudit('limitation_recorded', 'operator', "limitation:{$category}");

        return $report;
    }

    // =========================================================================
    // Executive readiness reports
    // =========================================================================

    public function generateExecutiveReport(
        string $scope,
        int    $gatesPassed,
        int    $gatesTotal,
        int    $limitationsOpen,
        int    $risksHigh,
        array  $params = []
    ): ExecutiveReadinessReport {
        if (!in_array($scope, ExecutiveReadinessReport::REPORT_SCOPES, true)) {
            throw new \InvalidArgumentException("Invalid report scope: {$scope}");
        }

        $overallScore     = $gatesTotal > 0 ? ($gatesPassed / $gatesTotal) : 0.0;
        $recommendation   = $this->deriveGoLiveRecommendation($overallScore, $risksHigh, $limitationsOpen);

        $report = ExecutiveReadinessReport::create([
            'exec_report_id'          => 'exec-' . Str::uuid(),
            'report_scope'            => $scope,
            'overall_readiness_score' => $overallScore,
            'gates_passed'            => $gatesPassed,
            'gates_total'             => $gatesTotal,
            'limitations_open'        => $limitationsOpen,
            'risks_high'              => $risksHigh,
            'go_live_recommendation'  => $recommendation,
            'autonomous_approval'     => false,
            'self_approve_blocked'    => true,
            'is_advisory'             => true,
            'exec_evidence'           => $params['evidence'] ?? null,
        ]);

        $this->recordAcceptanceAudit('report_generated', 'operator', "exec_report:{$scope}");

        return $report;
    }

    private function deriveGoLiveRecommendation(float $score, int $risksHigh, int $limitsOpen): string
    {
        if ($risksHigh > 0) {
            return 'blocked';
        }
        if ($score >= self::READINESS_PASS_THRESHOLD && $limitsOpen === 0) {
            return 'approved';
        }
        if ($score >= self::READINESS_PASS_THRESHOLD) {
            return 'conditional';
        }
        return 'deferred';
    }

    // =========================================================================
    // Technical readiness reports
    // =========================================================================

    public function generateTechnicalReport(string $domain, array $checks, array $params = []): TechnicalReadinessReport
    {
        if (!in_array($domain, TechnicalReadinessReport::REPORT_DOMAINS, true)) {
            throw new \InvalidArgumentException("Invalid report domain: {$domain}");
        }

        $soakPassed        = (bool) ($checks['soak_passed'] ?? false);
        $replayVerified    = (bool) ($checks['replay_integrity_verified'] ?? false);
        $contractCompliant = (bool) ($checks['contract_compliant'] ?? false);
        $ruleRegistryValid = (bool) ($checks['rule_registry_valid'] ?? false);
        $fleetSimPassed    = (bool) ($checks['fleet_simulation_passed'] ?? false);

        $score = ($soakPassed + $replayVerified + $contractCompliant + $ruleRegistryValid + $fleetSimPassed) / 5.0;

        $report = TechnicalReadinessReport::create([
            'tech_report_id'            => 'tech-' . Str::uuid(),
            'report_domain'             => $domain,
            'domain_readiness_score'    => $score,
            'soak_passed'               => $soakPassed,
            'replay_integrity_verified' => $replayVerified,
            'contract_compliant'        => $contractCompliant,
            'rule_registry_valid'       => $ruleRegistryValid,
            'fleet_simulation_passed'   => $fleetSimPassed,
            'is_advisory'               => true,
            'tech_evidence'             => $params['evidence'] ?? null,
        ]);

        $this->recordAcceptanceAudit('report_generated', 'system', "tech_report:{$domain}");

        return $report;
    }

    // =========================================================================
    // Production risk register
    // =========================================================================

    public function registerRisk(
        string $category,
        string $severity,
        string $description,
        array  $params = []
    ): ProductionRiskRegister {
        if (!in_array($category, ProductionRiskRegister::RISK_CATEGORIES, true)) {
            throw new \InvalidArgumentException("Invalid risk category: {$category}");
        }

        if (!in_array($severity, ProductionRiskRegister::RISK_SEVERITIES, true)) {
            throw new \InvalidArgumentException("Invalid risk severity: {$severity}");
        }

        $riskScore = min((float) ($params['risk_score'] ?? 1.0), self::MAX_RISK_SCORE);
        $state     = $params['risk_state'] ?? 'open';

        if (!in_array($state, ProductionRiskRegister::RISK_STATES, true)) {
            $state = 'open';
        }

        $risk = ProductionRiskRegister::create([
            'risk_id'               => 'risk-' . Str::uuid(),
            'risk_category'         => $category,
            'risk_severity'         => $severity,
            'risk_score'            => $riskScore,
            'risk_description'      => $description,
            'risk_state'            => $state,
            'cutover_blocker'       => $severity === 'critical',
            'autonomous_mitigation' => false,
            'is_advisory'           => true,
            'risk_evidence'         => $params['evidence'] ?? null,
        ]);

        $this->recordAcceptanceAudit('risk_registered', 'operator', "risk:{$category}");

        if ($severity === 'critical') {
            $this->recordCertAudit('risk_escalated', 'system', null);
        }

        return $risk;
    }

    // =========================================================================
    // Go-live validation runs
    // =========================================================================

    public function runGoLiveValidation(string $scope, array $checks, ?string $certId = null): GoLiveValidationRun
    {
        if (!in_array($scope, GoLiveValidationRun::VALIDATION_SCOPES, true)) {
            throw new \InvalidArgumentException("Invalid validation scope: {$scope}");
        }

        $soakMet       = (bool) ($checks['soak_requirement_met'] ?? false);
        $gateMet       = (bool) ($checks['gate_requirement_met'] ?? false);
        $riskOk        = (bool) ($checks['risk_acceptable'] ?? false);
        $rollbackReady = (bool) ($checks['rollback_ready'] ?? false);

        $score = ($soakMet + $gateMet + $riskOk + $rollbackReady) / 4.0;
        $state = ($score >= self::READINESS_PASS_THRESHOLD) ? 'passed' : 'failed';

        $run = GoLiveValidationRun::create([
            'go_live_run_id'        => 'golive-' . Str::uuid(),
            'validation_scope'      => $scope,
            'validation_state'      => $state,
            'validation_score'      => $score,
            'soak_requirement_met'  => $soakMet,
            'gate_requirement_met'  => $gateMet,
            'risk_acceptable'       => $riskOk,
            'rollback_ready'        => $rollbackReady,
            'autonomous_go_live'    => false,
            'replay_safe'           => true,
            'certification_id'      => $certId,
            'validation_evidence'   => $checks['evidence'] ?? null,
        ]);

        $this->recordAcceptanceAudit('go_live_validated', 'operator', "go_live:{$scope}");

        if ($state === 'passed') {
            $this->recordCertAudit('go_live_approved', 'operator', $certId);
        }

        return $run;
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function dashboardStats(): array
    {
        return [
            'total_certifications'    => XdrReadinessCertification::count(),
            'passed_certifications'   => XdrReadinessCertification::where('certification_state', 'passed')->count(),
            'total_gates'             => ProductionAcceptanceGate::count(),
            'gates_passed'            => ProductionAcceptanceGate::where('gate_passed', true)->count(),
            'open_limitations'        => OperationalLimitationReport::where('cutover_blocked', false)->count(),
            'cutover_blocking_limits' => OperationalLimitationReport::where('cutover_blocked', true)->count(),
            'open_risks'              => ProductionRiskRegister::where('risk_state', 'open')->count(),
            'critical_risks'          => ProductionRiskRegister::where('risk_severity', 'critical')->where('risk_state', 'open')->count(),
            'go_live_validations'     => GoLiveValidationRun::count(),
            'go_live_passed'          => GoLiveValidationRun::where('validation_state', 'passed')->count(),
        ];
    }

    // =========================================================================
    // Audit helpers
    // =========================================================================

    private function recordAcceptanceAudit(string $eventType, string $actorType, ?string $target): DeploymentAcceptanceAudit
    {
        return DeploymentAcceptanceAudit::create([
            'acceptance_audit_id'      => 'acc-audit-' . Str::uuid(),
            'audit_event_type'         => $eventType,
            'actor_type'               => $actorType,
            'target_entity'            => $target,
            'self_approve_blocked'     => true,
            'autonomous_action_taken'  => false,
            'destructive_action_taken' => false,
            'replay_safe'              => true,
            'audit_evidence'           => null,
        ]);
    }

    private function recordCertAudit(string $eventType, string $actorType, ?string $certId): FinalXdrCertificationAudit
    {
        return FinalXdrCertificationAudit::create([
            'cert_audit_id'            => 'cert-audit-' . Str::uuid(),
            'audit_event_type'         => $eventType,
            'actor_type'               => $actorType,
            'certification_id'         => $certId,
            'autonomous_action_taken'  => false,
            'destructive_action_taken' => false,
            'replay_safe'              => true,
            'audit_evidence'           => null,
        ]);
    }
}
