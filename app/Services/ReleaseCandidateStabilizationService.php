<?php

namespace App\Services;

use App\Models\ReleaseCandidateManifest;
use App\Models\FeatureFreezeAudit;
use App\Models\DeploymentArtifactReport;
use App\Models\PilotDeploymentPreparationRun;
use App\Models\OperationalValidationBaseline;
use App\Models\ReleaseBlockerReport;
use App\Models\DeploymentReproducibilityReport;
use App\Models\StabilizationValidationRun;
use App\Models\ReleaseCandidateAudit;
use Illuminate\Support\Str;

class ReleaseCandidateStabilizationService
{
    public const ADVISORY_ONLY         = true;
    public const MAX_PILOT_ENDPOINTS   = 20;
    public const RC_PASS_THRESHOLD     = 0.85;
    public const MAX_ARTIFACT_SIZE_KB  = 512000; // 500 MB
    public const MAX_DRIFT_DELTA       = 0.10;   // 10% degradation threshold

    // =========================================================================
    // Release candidate manifests
    // =========================================================================

    public function initiateReleaseCandidate(string $rcVersion, array $params = []): ReleaseCandidateManifest
    {
        $subsystemCount   = (int) ($params['subsystem_count']    ?? 0);
        $ruleCount        = (int) ($params['rule_count']         ?? 133);
        $huntDomainCount  = (int) ($params['hunt_domain_count']  ?? 150);
        $readinessScore   = min((float) ($params['readiness_score'] ?? 0.0), 1.0);

        $manifestContent = json_encode([
            'rc_version'        => $rcVersion,
            'subsystem_count'   => $subsystemCount,
            'rule_count'        => $ruleCount,
            'hunt_domain_count' => $huntDomainCount,
            'generated_at'      => now()->toISOString(),
        ]);
        $manifestHash = hash('sha256', $manifestContent);

        $manifest = ReleaseCandidateManifest::create([
            'manifest_id'              => 'rc-manifest-' . Str::uuid(),
            'rc_version'               => $rcVersion,
            'rc_state'                 => 'draft',
            'manifest_hash'            => $manifestHash,
            'subsystem_count'          => $subsystemCount,
            'rule_count'               => $ruleCount,
            'hunt_domain_count'        => $huntDomainCount,
            'readiness_score'          => $readinessScore,
            'feature_freeze_enforced'  => false,
            'soak_validated'           => (bool) ($params['soak_validated'] ?? false),
            'replay_safe'              => true,
            'autonomous_release'       => false,
            'is_advisory'              => true,
            'manifest_evidence'        => $params['evidence'] ?? null,
        ]);

        $this->recordRcAudit('rc_initiated', 'operator', $rcVersion);

        return $manifest;
    }

    public function freezeReleaseCandidate(ReleaseCandidateManifest $manifest): ReleaseCandidateManifest
    {
        $manifest->update([
            'rc_state'                => 'frozen',
            'feature_freeze_enforced' => true,
            'autonomous_release'      => false,
        ]);

        $this->recordFreezeAudit('freeze_initiated', 'full', null, $manifest->rc_version);
        $this->recordRcAudit('freeze_enforced', 'operator', $manifest->rc_version);

        return $manifest->fresh();
    }

    public function promoteRcState(ReleaseCandidateManifest $manifest, string $newState, float $readinessScore): ReleaseCandidateManifest
    {
        if (!in_array($newState, ReleaseCandidateManifest::RC_STATES, true)) {
            throw new \InvalidArgumentException("Invalid RC state: {$newState}");
        }

        $manifest->update([
            'rc_state'        => $newState,
            'readiness_score' => min($readinessScore, 1.0),
            'autonomous_release' => false,
        ]);

        return $manifest->fresh();
    }

    // =========================================================================
    // Feature freeze audit
    // =========================================================================

    public function recordFreezeAudit(
        string $event,
        string $scope,
        ?string $subsystem,
        ?string $rcVersion = null,
        bool $hotfixAllowed = false
    ): FeatureFreezeAudit {
        if (!in_array($event, FeatureFreezeAudit::FREEZE_EVENTS, true)) {
            throw new \InvalidArgumentException("Invalid freeze event: {$event}");
        }

        if (!in_array($scope, FeatureFreezeAudit::FREEZE_SCOPES, true)) {
            throw new \InvalidArgumentException("Invalid freeze scope: {$scope}");
        }

        return FeatureFreezeAudit::create([
            'freeze_audit_id'          => 'freeze-' . Str::uuid(),
            'freeze_event'             => $event,
            'freeze_scope'             => $scope,
            'subsystem'                => $subsystem,
            'scope_expansion_blocked'  => true,
            'hotfix_allowed'           => $hotfixAllowed,
            'autonomous_scope_change'  => false,
            'replay_safe'              => true,
            'rc_version'               => $rcVersion,
            'freeze_evidence'          => null,
        ]);
    }

    // =========================================================================
    // Deployment artifact reports
    // =========================================================================

    public function generateArtifact(string $artifactType, string $rcVersion, array $params = []): DeploymentArtifactReport
    {
        if (!in_array($artifactType, DeploymentArtifactReport::ARTIFACT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid artifact type: {$artifactType}");
        }

        $content     = json_encode(['type' => $artifactType, 'rc' => $rcVersion, 'ts' => now()->toISOString()]);
        $artifactHash= hash('sha256', $content);
        $sizekb      = min((int) ($params['size_kb'] ?? 1), self::MAX_ARTIFACT_SIZE_KB);

        $artifact = DeploymentArtifactReport::create([
            'artifact_report_id'   => 'artifact-' . Str::uuid(),
            'artifact_type'        => $artifactType,
            'artifact_state'       => 'generated',
            'artifact_hash'        => $artifactHash,
            'artifact_size_kb'     => $sizekb,
            'integrity_verified'   => true,
            'replay_safe'          => true,
            'autonomous_generation'=> false,
            'is_advisory'          => true,
            'rc_version'           => $rcVersion,
            'artifact_evidence'    => $params['evidence'] ?? null,
        ]);

        $this->recordRcAudit('artifact_generated', 'system', $rcVersion);

        return $artifact;
    }

    // =========================================================================
    // Pilot deployment preparation
    // =========================================================================

    public function runPilotPreparation(string $scope, int $endpointCount, array $checks, array $params = []): PilotDeploymentPreparationRun
    {
        if (!in_array($scope, PilotDeploymentPreparationRun::PREPARATION_SCOPES, true)) {
            throw new \InvalidArgumentException("Invalid preparation scope: {$scope}");
        }

        $endpointCount  = min($endpointCount, self::MAX_PILOT_ENDPOINTS);
        $onboardingReady= (bool) ($checks['onboarding_ready']  ?? false);
        $haReady        = (bool) ($checks['ha_ready']           ?? false);
        $rollbackReady  = (bool) ($checks['rollback_ready']     ?? false);
        $telemetryCont  = (bool) ($checks['telemetry_continuous']?? false);

        $score = ($onboardingReady + $haReady + $rollbackReady + $telemetryCont) / 4.0;
        $state = $score >= self::RC_PASS_THRESHOLD ? 'passed' : 'failed';

        return PilotDeploymentPreparationRun::create([
            'pilot_prep_run_id'     => 'pilot-prep-' . Str::uuid(),
            'preparation_scope'     => $scope,
            'preparation_state'     => $state,
            'pilot_endpoint_count'  => $endpointCount,
            'readiness_score'       => $score,
            'onboarding_ready'      => $onboardingReady,
            'ha_ready'              => $haReady,
            'rollback_ready'        => $rollbackReady,
            'telemetry_continuous'  => $telemetryCont,
            'replay_safe'           => true,
            'autonomous_rollout'    => false,
            'is_advisory'           => true,
            'preparation_evidence'  => $params['evidence'] ?? null,
        ]);
    }

    // =========================================================================
    // Operational validation baselines
    // =========================================================================

    public function recordOperationalBaseline(string $domain, float $score, float $previousScore, array $params = []): OperationalValidationBaseline
    {
        if (!in_array($domain, OperationalValidationBaseline::VALIDATION_DOMAINS, true)) {
            throw new \InvalidArgumentException("Invalid validation domain: {$domain}");
        }

        $driftDelta         = abs($score - $previousScore);
        $withinBounds       = $driftDelta <= self::MAX_DRIFT_DELTA;
        $degradationDetected= $score < $previousScore && $driftDelta > self::MAX_DRIFT_DELTA;

        $state = match(true) {
            $score >= 0.90 && $withinBounds => 'passing',
            $score >= 0.70                  => 'degraded',
            $score < 0.70                   => 'failing',
            default                         => 'recovering',
        };

        return OperationalValidationBaseline::create([
            'baseline_id'           => 'baseline-' . Str::uuid(),
            'validation_domain'     => $domain,
            'validation_state'      => $state,
            'baseline_score'        => min($score, 1.0),
            'previous_score'        => min($previousScore, 1.0),
            'drift_delta'           => $driftDelta,
            'within_bounds'         => $withinBounds,
            'replay_safe'           => true,
            'degradation_detected'  => $degradationDetected,
            'is_advisory'           => true,
            'baseline_evidence'     => $params['evidence'] ?? null,
        ]);
    }

    // =========================================================================
    // Release blocker reports
    // =========================================================================

    public function registerBlocker(string $category, string $severity, string $description, ?string $rcVersion = null): ReleaseBlockerReport
    {
        if (!in_array($category, ReleaseBlockerReport::BLOCKER_CATEGORIES, true)) {
            throw new \InvalidArgumentException("Invalid blocker category: {$category}");
        }

        if (!in_array($severity, ReleaseBlockerReport::BLOCKER_SEVERITIES, true)) {
            throw new \InvalidArgumentException("Invalid blocker severity: {$severity}");
        }

        $blocker = ReleaseBlockerReport::create([
            'blocker_report_id'    => 'blocker-' . Str::uuid(),
            'blocker_category'     => $category,
            'blocker_severity'     => $severity,
            'blocker_description'  => $description,
            'blocker_state'        => 'open',
            'release_blocked'      => $severity === 'critical',
            'autonomous_resolution'=> false,
            'is_advisory'          => true,
            'rc_version'           => $rcVersion,
            'blocker_evidence'     => null,
        ]);

        if ($severity === 'critical') {
            $this->recordRcAudit('blocker_raised', 'operator', $rcVersion);
        }

        return $blocker;
    }

    // =========================================================================
    // Deployment reproducibility reports
    // =========================================================================

    public function assessReproducibility(string $deploymentScope, array $checks, ?string $rcVersion = null): DeploymentReproducibilityReport
    {
        if (!in_array($deploymentScope, DeploymentReproducibilityReport::DEPLOYMENT_SCOPES, true)) {
            throw new \InvalidArgumentException("Invalid deployment scope: {$deploymentScope}");
        }

        $envMatched       = (bool) ($checks['environment_matched']          ?? false);
        $hashVerified     = (bool) ($checks['artifact_hash_verified']       ?? false);
        $configDet        = (bool) ($checks['configuration_deterministic']  ?? false);
        $replayVerified   = (bool) ($checks['replay_continuity_verified']   ?? false);

        $score = ($envMatched + $hashVerified + $configDet + $replayVerified) / 4.0;

        return DeploymentReproducibilityReport::create([
            'reproducibility_report_id' => 'repro-' . Str::uuid(),
            'deployment_scope'          => $deploymentScope,
            'reproducibility_score'     => $score,
            'environment_matched'       => $envMatched,
            'artifact_hash_verified'    => $hashVerified,
            'configuration_deterministic'=> $configDet,
            'replay_continuity_verified'=> $replayVerified,
            'is_advisory'               => true,
            'autonomous_deployment'     => false,
            'rc_version'                => $rcVersion,
            'reproducibility_evidence'  => $checks['evidence'] ?? null,
        ]);
    }

    // =========================================================================
    // Stabilization validation runs
    // =========================================================================

    public function recordStabilizationValidation(string $validationType, array $checks): StabilizationValidationRun
    {
        if (!in_array($validationType, StabilizationValidationRun::VALIDATION_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid validation type: {$validationType}");
        }

        $testsGreen    = (bool) ($checks['tests_green']     ?? false);
        $registryValid = (bool) ($checks['registry_valid']  ?? false);
        $contractsValid= (bool) ($checks['contracts_valid'] ?? false);
        $resilienceV   = (bool) ($checks['resilience_valid']?? false);
        $fleetSimV     = (bool) ($checks['fleet_sim_valid'] ?? false);

        $score = ($testsGreen + $registryValid + $contractsValid + $resilienceV + $fleetSimV) / 5.0;
        $state = $score >= self::RC_PASS_THRESHOLD ? 'passed' : 'failed';

        $run = StabilizationValidationRun::create([
            'stabilization_run_id'   => 'stab-' . Str::uuid(),
            'validation_type'        => $validationType,
            'validation_state'       => $state,
            'validation_score'       => $score,
            'tests_green'            => $testsGreen,
            'registry_valid'         => $registryValid,
            'contracts_valid'        => $contractsValid,
            'resilience_valid'       => $resilienceV,
            'fleet_sim_valid'        => $fleetSimV,
            'is_advisory'            => true,
            'replay_safe'            => true,
            'stabilization_evidence' => $checks['evidence'] ?? null,
        ]);

        if ($state === 'passed') {
            $this->recordRcAudit('validation_passed', 'system', null);
        }

        return $run;
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function dashboardStats(): array
    {
        return [
            'total_rc_manifests'       => ReleaseCandidateManifest::count(),
            'frozen_rcs'               => ReleaseCandidateManifest::where('feature_freeze_enforced', true)->count(),
            'total_artifacts'          => DeploymentArtifactReport::count(),
            'verified_artifacts'       => DeploymentArtifactReport::where('integrity_verified', true)->count(),
            'open_blockers'            => ReleaseBlockerReport::where('blocker_state', 'open')->count(),
            'critical_blockers'        => ReleaseBlockerReport::where('blocker_severity', 'critical')->where('blocker_state', 'open')->count(),
            'pilot_prep_runs'          => PilotDeploymentPreparationRun::count(),
            'pilot_prep_passed'        => PilotDeploymentPreparationRun::where('preparation_state', 'passed')->count(),
            'stabilization_runs'       => StabilizationValidationRun::count(),
            'stabilization_passed'     => StabilizationValidationRun::where('validation_state', 'passed')->count(),
        ];
    }

    // =========================================================================
    // Audit helper
    // =========================================================================

    private function recordRcAudit(string $eventType, string $actorType, ?string $rcVersion): ReleaseCandidateAudit
    {
        return ReleaseCandidateAudit::create([
            'rc_audit_id'              => 'rc-audit-' . Str::uuid(),
            'audit_event_type'         => $eventType,
            'actor_type'               => $actorType,
            'rc_version'               => $rcVersion,
            'autonomous_action_taken'  => false,
            'destructive_action_taken' => false,
            'replay_safe'              => true,
            'audit_evidence'           => null,
        ]);
    }
}
