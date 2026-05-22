<?php

namespace App\Services;

use App\Models\DeploymentPackageManifest;
use App\Models\DeploymentIntegrityReport;
use App\Models\RolloutValidationRun;
use App\Models\DeploymentUpgradeHistory;
use App\Models\EnvironmentValidationReport;
use App\Models\DeploymentDriftReport;
use App\Models\DeploymentObservabilitySnapshot;
use App\Models\RolloutCheckpointHistory;
use App\Models\EnterpriseDeploymentAudit;
use Illuminate\Support\Str;

class EnterpriseDeploymentHardeningService
{
    public const ADVISORY_ONLY = true;

    public const MAX_ROLLOUT_DURATION_S   = 3600;
    public const MAX_CANARY_ENDPOINTS     = 10;
    public const DRIFT_CRITICAL_THRESHOLD = 0.30;
    public const DRIFT_HIGH_THRESHOLD     = 0.20;
    public const DRIFT_MEDIUM_THRESHOLD   = 0.10;
    public const MAX_VALIDATION_RUNTIME_S = 300;

    public const SUPPORTED_ENVIRONMENTS = ['local', 'staging', 'production'];

    // =========================================================================
    // Package manifest
    // =========================================================================

    public function recordPackageManifest(
        string $packageId,
        string $version,
        string $packageHashSha256,
        array  $params = []
    ): DeploymentPackageManifest {
        $isSigned     = $params['is_signed']     ?? false;
        $sigValid     = $isSigned && ($params['signature_valid'] ?? false);
        $depIntegrity = $params['dependency_integrity_verified'] ?? false;

        return DeploymentPackageManifest::create([
            'manifest_id'                    => 'dpm-' . Str::uuid(),
            'package_id'                     => $packageId,
            'version'                        => $version,
            'package_hash_sha256'            => $packageHashSha256,
            'image_digest'                   => $params['image_digest']    ?? null,
            'is_signed'                      => $isSigned,
            'signature_valid'                => $sigValid,
            'dependency_integrity_verified'  => $depIntegrity,
            'is_advisory'                    => true,
            'manifest_payload'               => $params['payload']         ?? null,
        ]);
    }

    // =========================================================================
    // Integrity report
    // =========================================================================

    public function runIntegrityReport(
        string $packageId,
        string $version,
        string $packageHashSha256,
        string $expectedHash,
        array  $params = []
    ): DeploymentIntegrityReport {
        $hashMatch           = hash_equals($expectedHash, $packageHashSha256);
        $signatureValid      = $params['signature_valid']      ?? false;
        $dependencyDrift     = $params['dependency_drift_detected'] ?? false;
        $validationPassed    = $hashMatch && $signatureValid && !$dependencyDrift;

        return DeploymentIntegrityReport::create([
            'report_id'                 => 'dir-' . Str::uuid(),
            'package_id'                => $packageId,
            'version'                   => $version,
            'validation_passed'         => $validationPassed,
            'hash_match'                => $hashMatch,
            'signature_valid'           => $signatureValid,
            'dependency_drift_detected' => $dependencyDrift,
            'replay_safe'               => true,
            'is_advisory'               => true,
            'integrity_evidence'        => $params['evidence'] ?? null,
        ]);
    }

    // =========================================================================
    // Rollout validation
    // =========================================================================

    public function runRolloutValidation(
        string $rolloutId,
        string $stage,
        int    $canaryEndpoints = 0,
        array  $params = []
    ): RolloutValidationRun {
        if (!in_array($stage, RolloutValidationRun::STAGES, true)) {
            throw new \InvalidArgumentException("Invalid rollout stage: {$stage}");
        }

        if ($canaryEndpoints > self::MAX_CANARY_ENDPOINTS) {
            throw new \InvalidArgumentException(
                "Canary endpoint count {$canaryEndpoints} exceeds MAX_CANARY_ENDPOINTS (" . self::MAX_CANARY_ENDPOINTS . ")."
            );
        }

        $duration = min(
            (float) ($params['rollout_duration_s'] ?? 0.0),
            self::MAX_ROLLOUT_DURATION_S
        );

        return RolloutValidationRun::create([
            'run_id'              => 'rvr-' . Str::uuid(),
            'rollout_id'          => $rolloutId,
            'stage'               => $stage,
            'canary_endpoints'    => $canaryEndpoints,
            'rollout_success'     => $params['rollout_success']    ?? false,
            'checkpoint_passed'   => $params['checkpoint_passed']  ?? false,
            'bounded_concurrency' => true,
            'rollout_duration_s'  => $duration,
            'replay_safe'         => true,
            'is_advisory'         => true,
            'rollout_evidence'    => $params['evidence']           ?? null,
        ]);
    }

    // =========================================================================
    // Upgrade history
    // =========================================================================

    public function recordUpgradeHistory(
        string $fromVersion,
        string $toVersion,
        array  $params = []
    ): DeploymentUpgradeHistory {
        $migrationPassed  = $params['migration_passed']   ?? false;
        $rollbackCompat   = $params['rollback_compatible'] ?? false;
        $depDrift         = $params['dependency_drift_detected'] ?? false;
        $compatVerified   = $migrationPassed && $rollbackCompat;

        return DeploymentUpgradeHistory::create([
            'upgrade_id'                => 'duh-' . Str::uuid(),
            'from_version'              => $fromVersion,
            'to_version'                => $toVersion,
            'migration_passed'          => $migrationPassed,
            'rollback_compatible'       => $rollbackCompat,
            'dependency_drift_detected' => $depDrift,
            'compatibility_verified'    => $compatVerified,
            'replay_safe'               => true,
            'is_advisory'               => true,
            'upgrade_evidence'          => $params['evidence'] ?? null,
        ]);
    }

    // =========================================================================
    // Environment validation
    // =========================================================================

    public function runEnvironmentValidation(
        string $environment,
        array  $checks = []
    ): EnvironmentValidationReport {
        if (!in_array($environment, self::SUPPORTED_ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException("Unsupported environment: {$environment}");
        }

        $servicesHealthy  = $checks['services_healthy']   ?? false;
        $queueAvailable   = $checks['queue_available']    ?? false;
        $storageAvailable = $checks['storage_available']  ?? false;
        $replayContinuous = $checks['replay_continuous']  ?? false;
        $telemetryCont    = $checks['telemetry_continuous']?? false;
        $overallValid     = $servicesHealthy && $queueAvailable
                         && $storageAvailable && $replayContinuous;

        return EnvironmentValidationReport::create([
            'validation_id'       => 'evr-' . Str::uuid(),
            'environment'         => $environment,
            'services_healthy'    => $servicesHealthy,
            'queue_available'     => $queueAvailable,
            'storage_available'   => $storageAvailable,
            'replay_continuous'   => $replayContinuous,
            'telemetry_continuous'=> $telemetryCont,
            'overall_valid'       => $overallValid,
            'is_advisory'         => true,
            'validation_details'  => $checks ?: null,
        ]);
    }

    // =========================================================================
    // Deployment drift
    // =========================================================================

    public function recordDeploymentDrift(
        string $service,
        string $expectedVersion,
        string $actualVersion,
        array  $params = []
    ): DeploymentDriftReport {
        if ($expectedVersion === $actualVersion) {
            $driftScore    = 0.0;
            $driftDetected = false;
        } else {
            $driftScore    = (float) ($params['drift_score'] ?? 0.10);
            $driftDetected = $driftScore > 0.0;
        }

        $severity = match(true) {
            $driftScore >= self::DRIFT_CRITICAL_THRESHOLD => 'critical',
            $driftScore >= self::DRIFT_HIGH_THRESHOLD     => 'high',
            $driftScore >= self::DRIFT_MEDIUM_THRESHOLD   => 'medium',
            default                                       => 'low',
        };

        return DeploymentDriftReport::create([
            'drift_id'         => 'ddr-' . Str::uuid(),
            'service'          => $service,
            'expected_version' => $expectedVersion,
            'actual_version'   => $actualVersion,
            'drift_detected'   => $driftDetected,
            'drift_severity'   => $severity,
            'drift_score'      => $driftScore,
            'is_advisory'      => true,
            'drift_evidence'   => $params['evidence'] ?? null,
        ]);
    }

    // =========================================================================
    // Observability snapshot
    // =========================================================================

    public function recordObservabilitySnapshot(
        string $deploymentId,
        float  $successRate,
        float  $rolloutDurationS,
        array  $params = []
    ): DeploymentObservabilitySnapshot {
        $clampedRate     = min(1.0, max(0.0, $successRate));
        $clampedDuration = min((float) self::MAX_ROLLOUT_DURATION_S, max(0.0, $rolloutDurationS));

        return DeploymentObservabilitySnapshot::create([
            'snapshot_id'                => 'dos-' . Str::uuid(),
            'deployment_id'              => $deploymentId,
            'success_rate'               => $clampedRate,
            'rollout_duration_s'         => $clampedDuration,
            'restart_frequency'          => $params['restart_frequency']     ?? 0.0,
            'deployment_latency_ms'      => $params['deployment_latency_ms'] ?? 0.0,
            'replay_continuity_verified' => $params['replay_continuity_verified'] ?? false,
            'is_advisory'                => true,
            'observability_payload'      => $params['payload']               ?? null,
        ]);
    }

    // =========================================================================
    // Rollout checkpoint
    // =========================================================================

    public function recordRolloutCheckpoint(
        string $rolloutId,
        string $checkpointType,
        bool   $passed,
        array  $params = []
    ): RolloutCheckpointHistory {
        if (!in_array($checkpointType, RolloutCheckpointHistory::CHECKPOINT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid checkpoint type: {$checkpointType}");
        }

        return RolloutCheckpointHistory::create([
            'checkpoint_id'      => 'rch-' . Str::uuid(),
            'rollout_id'         => $rolloutId,
            'checkpoint_type'    => $checkpointType,
            'passed'             => $passed,
            'replay_safe'        => true,
            'is_advisory'        => true,
            'validation_details' => $params['details'] ?? null,
        ]);
    }

    // =========================================================================
    // Deployment audit
    // =========================================================================

    public function recordDeploymentAudit(
        string $deploymentId,
        string $actionType,
        string $actor,
        string $outcome,
        array  $params = []
    ): EnterpriseDeploymentAudit {
        if (!in_array($actionType, EnterpriseDeploymentAudit::ACTION_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid action type: {$actionType}");
        }

        if (!in_array($outcome, EnterpriseDeploymentAudit::OUTCOMES, true)) {
            throw new \InvalidArgumentException("Invalid outcome: {$outcome}");
        }

        return EnterpriseDeploymentAudit::create([
            'audit_id'      => 'eda-' . Str::uuid(),
            'deployment_id' => $deploymentId,
            'action_type'   => $actionType,
            'actor'         => $actor,
            'outcome'       => $outcome,
            'is_advisory'   => true,
            'audit_payload' => $params['payload'] ?? null,
        ]);
    }

    // =========================================================================
    // Dashboard
    // =========================================================================

    public function dashboardStats(): array
    {
        return [
            'total_manifests'        => DeploymentPackageManifest::count(),
            'unsigned_packages'      => DeploymentPackageManifest::where('is_signed', false)->count(),
            'integrity_failures'     => DeploymentIntegrityReport::where('validation_passed', false)->count(),
            'rollout_runs'           => RolloutValidationRun::count(),
            'rollout_failures'       => RolloutValidationRun::where('rollout_success', false)->count(),
            'upgrade_history'        => DeploymentUpgradeHistory::count(),
            'incompatible_upgrades'  => DeploymentUpgradeHistory::where('compatibility_verified', false)->count(),
            'env_validation_runs'    => EnvironmentValidationReport::count(),
            'env_validation_failures'=> EnvironmentValidationReport::where('overall_valid', false)->count(),
            'drift_reports'          => DeploymentDriftReport::count(),
            'critical_drift'         => DeploymentDriftReport::where('drift_severity', 'critical')->count(),
            'checkpoint_failures'    => RolloutCheckpointHistory::where('passed', false)->count(),
            'audit_events'           => EnterpriseDeploymentAudit::count(),
        ];
    }
}
