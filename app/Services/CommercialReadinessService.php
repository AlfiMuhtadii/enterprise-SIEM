<?php

namespace App\Services;

use App\Models\TenantOnboardingRun;
use App\Models\DeploymentPackageProfile;
use App\Models\CommercialReleaseHistory;
use App\Models\SupportBundleExport;
use App\Models\DeploymentReadinessReport;
use App\Models\CustomerOperationsHealth;
use App\Models\OnboardingCheckpointHistory;
use App\Models\ReleaseCompatibilityReport;
use App\Models\CommercialProductAudit;
use Illuminate\Support\Str;

class CommercialReadinessService
{
    public const ADVISORY_ONLY             = true;
    public const MAX_EXPORT_SIZE_MB        = 500;
    public const MAX_ONBOARDING_ENDPOINTS  = 100;
    public const READINESS_PASS_THRESHOLD  = 0.80;
    public const SUPPORTED_ENVIRONMENTS    = ['local', 'staging', 'production'];

    // =========================================================================
    // Tenant onboarding
    // =========================================================================

    public function initiateOnboarding(
        string $tenantId,
        string $onboardingPhase,
        int    $endpointCount = 0,
        array  $params = []
    ): TenantOnboardingRun {
        if (!in_array($onboardingPhase, TenantOnboardingRun::ONBOARDING_PHASES, true)) {
            throw new \InvalidArgumentException("Invalid onboarding phase: {$onboardingPhase}");
        }

        $boundedCount = min($endpointCount, self::MAX_ONBOARDING_ENDPOINTS);

        $run = TenantOnboardingRun::create([
            'onboarding_run_id' => 'tor-' . Str::uuid(),
            'tenant_id'         => $tenantId,
            'onboarding_phase'  => $onboardingPhase,
            'onboarding_state'  => 'initiated',
            'endpoint_count'    => $boundedCount,
            'readiness_score'   => 0.0,
            'validation_passed' => false,
            'rollback_capable'  => true,
            'replay_safe'       => true,
            'is_advisory'       => true,
            'onboarding_evidence' => $params['evidence'] ?? null,
        ]);

        $this->recordAudit('onboarding_initiated', 'operator', $tenantId, [
            'onboarding_run_id' => $run->onboarding_run_id,
            'phase'             => $onboardingPhase,
        ]);

        return $run;
    }

    public function advanceOnboarding(
        TenantOnboardingRun $run,
        string $newState,
        float  $readinessScore = 0.0,
        array  $params = []
    ): TenantOnboardingRun {
        if (!in_array($newState, TenantOnboardingRun::ONBOARDING_STATES, true)) {
            throw new \InvalidArgumentException("Invalid onboarding state: {$newState}");
        }

        $score = min(max($readinessScore, 0.0), 1.0);

        $run->update([
            'onboarding_state'  => $newState,
            'readiness_score'   => $score,
            'validation_passed' => $score >= self::READINESS_PASS_THRESHOLD,
        ]);

        return $run->fresh();
    }

    // =========================================================================
    // Onboarding checkpoints
    // =========================================================================

    public function recordOnboardingCheckpoint(
        string $onboardingRunId,
        string $checkpointType,
        bool   $passed,
        array  $params = []
    ): OnboardingCheckpointHistory {
        if (!in_array($checkpointType, OnboardingCheckpointHistory::CHECKPOINT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid checkpoint type: {$checkpointType}");
        }

        return OnboardingCheckpointHistory::create([
            'onboarding_checkpoint_id' => 'ocp-' . Str::uuid(),
            'onboarding_run_id'        => $onboardingRunId,
            'checkpoint_type'          => $checkpointType,
            'passed'                   => $passed,
            'rollback_triggered'       => $params['rollback_triggered'] ?? false,
            'replay_safe'              => true,
            'is_advisory'              => true,
            'checkpoint_details'       => $params['details'] ?? null,
        ]);
    }

    // =========================================================================
    // Deployment package profiles
    // =========================================================================

    public function createPackageProfile(
        string $profileName,
        string $environment,
        string $deploymentTier,
        string $packageVersion,
        array  $params = []
    ): DeploymentPackageProfile {
        if (!in_array($environment, self::SUPPORTED_ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException("Invalid environment: {$environment}");
        }

        if (!in_array($deploymentTier, DeploymentPackageProfile::DEPLOYMENT_TIERS, true)) {
            throw new \InvalidArgumentException("Invalid deployment tier: {$deploymentTier}");
        }

        $checksum = $params['checksum'] ?? hash('sha256', $profileName . $packageVersion . $environment);

        $profile = DeploymentPackageProfile::create([
            'package_profile_id'          => 'dpp-' . Str::uuid(),
            'profile_name'                => $profileName,
            'environment'                 => $environment,
            'deployment_tier'             => $deploymentTier,
            'package_version'             => $packageVersion,
            'checksum'                    => $checksum,
            'checksum_verified'           => $params['checksum_verified'] ?? false,
            'compatibility_verified'      => $params['compatibility_verified'] ?? false,
            'is_active'                   => true,
            'infrastructure_requirements' => $params['infrastructure_requirements'] ?? null,
            'package_manifest'            => $params['package_manifest'] ?? null,
        ]);

        $this->recordAudit('package_validated', 'operator', $profile->package_profile_id, [
            'version'     => $packageVersion,
            'environment' => $environment,
        ]);

        return $profile;
    }

    public function verifyPackageChecksum(DeploymentPackageProfile $profile): bool
    {
        $expected = hash('sha256', $profile->profile_name . $profile->package_version . $profile->environment);
        $valid    = $profile->checksum === $expected;

        $profile->update(['checksum_verified' => $valid]);
        return $valid;
    }

    // =========================================================================
    // Commercial release history
    // =========================================================================

    public function recordRelease(
        string $releaseVersion,
        string $releaseStage,
        string $releaseState,
        array  $params = []
    ): CommercialReleaseHistory {
        if (!in_array($releaseStage, CommercialReleaseHistory::RELEASE_STAGES, true)) {
            throw new \InvalidArgumentException("Invalid release stage: {$releaseStage}");
        }

        if (!in_array($releaseState, CommercialReleaseHistory::RELEASE_STATES, true)) {
            throw new \InvalidArgumentException("Invalid release state: {$releaseState}");
        }

        $manifestHash = hash('sha256', $releaseVersion . $releaseStage . json_encode($params['manifest'] ?? []));

        $release = CommercialReleaseHistory::create([
            'release_id'           => 'crl-' . Str::uuid(),
            'release_version'      => $releaseVersion,
            'release_stage'        => $releaseStage,
            'release_state'        => $releaseState,
            'manifest_hash'        => $manifestHash,
            'migration_compatible' => $params['migration_compatible'] ?? true,
            'rollback_compatible'  => $params['rollback_compatible'] ?? true,
            'self_approve_blocked' => true,
            'replay_safe'          => true,
            'release_evidence'     => $params['evidence'] ?? null,
        ]);

        $this->recordAudit('release_published', 'operator', $release->release_id, [
            'version' => $releaseVersion,
            'stage'   => $releaseStage,
        ]);

        return $release;
    }

    // =========================================================================
    // Release compatibility reports
    // =========================================================================

    public function checkReleaseCompatibility(
        string $releaseId,
        string $fromVersion,
        string $toVersion,
        array  $params = []
    ): ReleaseCompatibilityReport {
        $report = ReleaseCompatibilityReport::create([
            'compatibility_report_id'  => 'rcr-' . Str::uuid(),
            'release_id'               => $releaseId,
            'from_version'             => $fromVersion,
            'to_version'               => $toVersion,
            'schema_compatible'        => $params['schema_compatible'] ?? true,
            'migration_safe'           => $params['migration_safe'] ?? true,
            'rollback_safe'            => $params['rollback_safe'] ?? true,
            'upgrade_advisory_issued'  => $params['upgrade_advisory_issued'] ?? false,
            'replay_safe'              => true,
            'compatibility_evidence'   => $params['evidence'] ?? null,
        ]);

        $this->recordAudit('compatibility_checked', 'system', $releaseId, [
            'from' => $fromVersion,
            'to'   => $toVersion,
        ]);

        return $report;
    }

    // =========================================================================
    // Support bundle exports
    // =========================================================================

    public function exportSupportBundle(
        string $bundleType,
        string $exportScope,
        float  $exportSizeMb = 0.0,
        array  $params = []
    ): SupportBundleExport {
        if (!in_array($bundleType, SupportBundleExport::BUNDLE_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid bundle type: {$bundleType}");
        }

        if (!in_array($exportScope, SupportBundleExport::EXPORT_SCOPES, true)) {
            throw new \InvalidArgumentException("Invalid export scope: {$exportScope}");
        }

        $bounded = min($exportSizeMb, self::MAX_EXPORT_SIZE_MB);

        $bundle = SupportBundleExport::create([
            'bundle_export_id'         => 'sbe-' . Str::uuid(),
            'bundle_type'              => $bundleType,
            'export_scope'             => $exportScope,
            'export_size_mb'           => $bounded,
            'size_bounded'             => true,
            'destructive_action_taken' => false,
            'replay_safe'              => true,
            'is_advisory'              => true,
            'bundle_evidence'          => $params['evidence'] ?? null,
        ]);

        $this->recordAudit('support_exported', 'operator', $bundle->bundle_export_id, [
            'type'  => $bundleType,
            'scope' => $exportScope,
            'size'  => $bounded,
        ]);

        return $bundle;
    }

    // =========================================================================
    // Deployment readiness reports
    // =========================================================================

    public function assessDeploymentReadiness(
        string $tenantId,
        string $environment,
        array  $checks = []
    ): DeploymentReadinessReport {
        if (!in_array($environment, self::SUPPORTED_ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException("Invalid environment: {$environment}");
        }

        $onboardingComplete = $checks['onboarding_complete'] ?? false;
        $telemetryHealthy   = $checks['telemetry_healthy']   ?? false;
        $environmentValid   = $checks['environment_valid']   ?? false;

        $score = round(
            (($onboardingComplete ? 1 : 0) + ($telemetryHealthy ? 1 : 0) + ($environmentValid ? 1 : 0)) / 3,
            2
        );

        $report = DeploymentReadinessReport::create([
            'readiness_report_id' => 'drr-' . Str::uuid(),
            'tenant_id'           => $tenantId,
            'environment'         => $environment,
            'readiness_score'     => $score,
            'onboarding_complete' => $onboardingComplete,
            'telemetry_healthy'   => $telemetryHealthy,
            'environment_valid'   => $environmentValid,
            'deployment_ready'    => $score >= self::READINESS_PASS_THRESHOLD,
            'replay_safe'         => true,
            'is_advisory'         => true,
            'readiness_evidence'  => $checks['evidence'] ?? null,
        ]);

        $this->recordAudit('readiness_assessed', 'system', $tenantId, [
            'readiness_report_id' => $report->readiness_report_id,
            'score'               => $score,
        ]);

        return $report;
    }

    // =========================================================================
    // Customer operations health
    // =========================================================================

    public function updateCustomerHealth(
        string $tenantId,
        array  $healthChecks = []
    ): CustomerOperationsHealth {
        $deploymentHealthy   = $healthChecks['deployment_healthy']   ?? false;
        $telemetryContinuous = $healthChecks['telemetry_continuous'] ?? false;
        $supportReady        = $healthChecks['support_ready']        ?? false;
        $onboardingComplete  = $healthChecks['onboarding_complete']  ?? false;

        $score = round(
            (($deploymentHealthy ? 1 : 0) + ($telemetryContinuous ? 1 : 0)
             + ($supportReady ? 1 : 0) + ($onboardingComplete ? 1 : 0)) / 4,
            2
        );

        $state = match(true) {
            $score >= 0.90 => 'healthy',
            $score >= 0.70 => 'degraded',
            $score >= 0.50 => 'at_risk',
            default        => 'critical',
        };

        $existing = CustomerOperationsHealth::where('tenant_id', $tenantId)->first();

        if ($existing) {
            $existing->update([
                'health_score'         => $score,
                'deployment_healthy'   => $deploymentHealthy,
                'telemetry_continuous' => $telemetryContinuous,
                'support_ready'        => $supportReady,
                'onboarding_complete'  => $onboardingComplete,
                'health_state'         => $state,
                'health_evidence'      => $healthChecks['evidence'] ?? null,
            ]);
            $record = $existing->fresh();
        } else {
            $record = CustomerOperationsHealth::create([
                'health_record_id'     => 'coh-' . Str::uuid(),
                'tenant_id'            => $tenantId,
                'health_score'         => $score,
                'deployment_healthy'   => $deploymentHealthy,
                'telemetry_continuous' => $telemetryContinuous,
                'support_ready'        => $supportReady,
                'onboarding_complete'  => $onboardingComplete,
                'health_state'         => $state,
                'health_evidence'      => $healthChecks['evidence'] ?? null,
            ]);
        }

        $this->recordAudit('health_updated', 'system', $tenantId, [
            'health_score' => $score,
            'state'        => $state,
        ]);

        return $record;
    }

    // =========================================================================
    // Audit
    // =========================================================================

    public function recordAudit(
        string  $auditEventType,
        string  $actorType,
        ?string $targetEntity = null,
        array   $evidence = []
    ): CommercialProductAudit {
        if (!in_array($auditEventType, CommercialProductAudit::AUDIT_EVENT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid audit event type: {$auditEventType}");
        }

        if (!in_array($actorType, CommercialProductAudit::ACTOR_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid actor type: {$actorType}");
        }

        return CommercialProductAudit::create([
            'commercial_audit_id'      => 'cpa-' . Str::uuid(),
            'audit_event_type'         => $auditEventType,
            'actor_type'               => $actorType,
            'target_entity'            => $targetEntity,
            'autonomous_action_taken'  => false,
            'destructive_action_taken' => false,
            'replay_safe'              => true,
            'audit_evidence'           => $evidence ?: null,
        ]);
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function dashboardStats(): array
    {
        return [
            'onboarding_runs'          => TenantOnboardingRun::count(),
            'active_packages'          => DeploymentPackageProfile::where('is_active', true)->count(),
            'releases_published'       => CommercialReleaseHistory::where('release_state', 'published')->count(),
            'support_exports'          => SupportBundleExport::count(),
            'readiness_reports'        => DeploymentReadinessReport::count(),
            'deployments_ready'        => DeploymentReadinessReport::where('deployment_ready', true)->count(),
            'healthy_tenants'          => CustomerOperationsHealth::where('health_state', 'healthy')->count(),
            'compatibility_reports'    => ReleaseCompatibilityReport::count(),
            'audit_events'             => CommercialProductAudit::count(),
        ];
    }
}
