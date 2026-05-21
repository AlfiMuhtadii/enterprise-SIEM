<?php

namespace App\Services;

use App\Models\DeploymentReadinessRun;
use App\Models\EnvironmentDriftReport;
use App\Models\GoNogoDecision;
use App\Models\OperationalRunbook;
use App\Models\OperationalRunbookVersion;
use App\Models\ReleaseApprovalRequest;
use App\Models\ReleaseAuditEvent;
use App\Models\ReleaseManifest;
use App\Models\RollbackValidationRun;
use Illuminate\Support\Str;

/**
 * Production Readiness / Release Governance Phase 1.
 *
 * All operations are advisory-only, approval-gated, and replay-safe.
 * No autonomous deployment, destructive rollback, or hidden environment mutation.
 * All release decisions must be explicit operator actions.
 */
class ReleaseGovernanceService
{
    // ─── Drift type registry ─────────────────────────────────────────────────

    public const DRIFT_TYPES = [
        'config_version', 'schema_mismatch', 'dependency_mismatch',
        'migration_drift', 'validator_drift', 'dashboard_drift',
        'route_drift', 'policy_drift',
    ];

    // ─── Runbook types ────────────────────────────────────────────────────────

    public const RUNBOOK_TYPES = [
        'deployment', 'rollback', 'incident_response', 'degraded_mode', 'replay_recovery',
    ];

    // ─── Rollback readiness weights ───────────────────────────────────────────

    private const ROLLBACK_WEIGHTS = [
        'migration_reversible'  => 0.30,
        'schema_rollback_safe'  => 0.25,
        'replay_safe'           => 0.25,
        'data_integrity_ok'     => 0.20,
    ];

    // ─── Release Manifest ─────────────────────────────────────────────────────

    /**
     * Create a deterministic, immutable release manifest.
     */
    public function createManifest(
        string $releaseVersion,
        array  $componentVersions,
        array  $migrationVersions,
        array  $schemaVersions,
        string $createdBy,
        string $replayValidatorVersion = '1.0.0',
        string $releaseNotes = '',
        string $rollbackReference = '',
    ): ReleaseManifest {
        $releaseId = 'rel-' . Str::random(32);

        $canonical = [
            'release_version'          => $releaseVersion,
            'component_versions'       => $componentVersions,
            'migration_versions'       => $migrationVersions,
            'schema_versions'          => $schemaVersions,
            'replay_validator_version' => $replayValidatorVersion,
            'rollback_reference'       => $rollbackReference,
        ];
        ksort($canonical);
        $manifestHash = hash('sha256', json_encode($canonical));

        $manifest = new ReleaseManifest([
            'release_id'               => $releaseId,
            'release_version'          => $releaseVersion,
            'component_versions'       => $componentVersions,
            'migration_versions'       => $migrationVersions,
            'schema_versions'          => $schemaVersions,
            'replay_validator_version' => $replayValidatorVersion,
            'release_notes'            => $releaseNotes,
            'rollback_reference'       => $rollbackReference,
            'manifest_hash'            => $manifestHash,
            'status'                   => ReleaseManifest::STATUS_DRAFT,
            'created_by'               => $createdBy,
        ]);
        $manifest->save();

        $this->auditEvent($releaseId, 'manifest_created', $createdBy, [
            'release_version' => $releaseVersion,
            'manifest_hash'   => $manifestHash,
        ]);

        return $manifest;
    }

    // ─── Deployment Readiness ─────────────────────────────────────────────────

    /**
     * Run preflight deployment readiness validation.
     * Returns deterministic go/no-go verdict — no automatic deployment.
     */
    public function runDeploymentReadiness(
        string $triggeredBy,
        string $releaseId = '',
        bool   $migrationReady = false,
        bool   $schemaCompatible = false,
        bool   $replayValidatorHealthy = false,
        bool   $queueHealthy = false,
        bool   $storagePressureOk = false,
        bool   $degradedModeClear = false,
        bool   $workerHealthOk = false,
        bool   $rollbackReady = false,
        array  $validationDetails = [],
    ): DeploymentReadinessRun {
        $allChecks = [
            $migrationReady, $schemaCompatible, $replayValidatorHealthy,
            $queueHealthy, $storagePressureOk, $degradedModeClear,
            $workerHealthOk, $rollbackReady,
        ];
        $passCount = count(array_filter($allChecks));
        $totalChecks = count($allChecks);

        $verdict = ($passCount === $totalChecks)
            ? DeploymentReadinessRun::VERDICT_GO
            : DeploymentReadinessRun::VERDICT_NO_GO;

        $run = new DeploymentReadinessRun([
            'run_id'                   => 'drr-' . Str::random(32),
            'release_id'               => $releaseId ?: null,
            'validator_status'         => 'completed',
            'migration_ready'          => $migrationReady,
            'schema_compatible'        => $schemaCompatible,
            'replay_validator_healthy' => $replayValidatorHealthy,
            'queue_healthy'            => $queueHealthy,
            'storage_pressure_ok'      => $storagePressureOk,
            'degraded_mode_clear'      => $degradedModeClear,
            'worker_health_ok'         => $workerHealthOk,
            'rollback_ready'           => $rollbackReady,
            'overall_verdict'          => $verdict,
            'validation_details'       => array_merge($validationDetails, [
                'pass_count'   => $passCount,
                'total_checks' => $totalChecks,
            ]),
            'triggered_by'             => $triggeredBy,
        ]);
        $run->save();

        if ($releaseId) {
            $this->auditEvent($releaseId, 'readiness_run_completed', $triggeredBy, [
                'run_id'  => $run->run_id,
                'verdict' => $verdict,
                'passed'  => $passCount,
                'total'   => $totalChecks,
            ]);
        }

        return $run;
    }

    // ─── Environment Drift ────────────────────────────────────────────────────

    /**
     * Record a detected environment drift finding.
     * No automatic reconciliation — advisory reporting only.
     */
    public function detectDrift(
        string  $driftType,
        string  $component,
        string  $detectedBy,
        string  $releaseId = '',
        string  $expectedValue = '',
        string  $observedValue = '',
        string  $severity = EnvironmentDriftReport::SEVERITY_LOW,
        bool    $isBlocking = false,
    ): EnvironmentDriftReport {
        if (!in_array($driftType, self::DRIFT_TYPES, true)) {
            throw new \InvalidArgumentException("Unknown drift_type: {$driftType}");
        }

        $report = new EnvironmentDriftReport([
            'report_id'      => 'edr-' . Str::random(32),
            'release_id'     => $releaseId ?: null,
            'drift_type'     => $driftType,
            'component'      => $component,
            'expected_value' => $expectedValue,
            'observed_value' => $observedValue,
            'severity'       => $severity,
            'is_blocking'    => $isBlocking,
            'detected_by'    => $detectedBy,
        ]);
        $report->save();

        if ($releaseId) {
            $this->auditEvent($releaseId, 'drift_detected', $detectedBy, [
                'report_id'   => $report->report_id,
                'drift_type'  => $driftType,
                'component'   => $component,
                'severity'    => $severity,
                'is_blocking' => $isBlocking,
            ]);
        }

        return $report;
    }

    // ─── Rollback Validation ──────────────────────────────────────────────────

    /**
     * Validate rollback readiness for a given release.
     * Computes deterministic readiness_score — no destructive rollback bypass.
     */
    public function runRollbackValidation(
        string $rollbackTargetVersion,
        string $validatedBy,
        string $releaseId = '',
        bool   $migrationReversible = false,
        bool   $schemaRollbackSafe = false,
        bool   $replaySafe = false,
        bool   $dataIntegrityOk = false,
        array  $simulationResults = [],
    ): RollbackValidationRun {
        $score = 0.0;
        $score += $migrationReversible  ? self::ROLLBACK_WEIGHTS['migration_reversible']  : 0.0;
        $score += $schemaRollbackSafe   ? self::ROLLBACK_WEIGHTS['schema_rollback_safe']  : 0.0;
        $score += $replaySafe           ? self::ROLLBACK_WEIGHTS['replay_safe']           : 0.0;
        $score += $dataIntegrityOk      ? self::ROLLBACK_WEIGHTS['data_integrity_ok']     : 0.0;

        $verdict = match (true) {
            $score >= 1.0 => RollbackValidationRun::VERDICT_READY,
            $score >= 0.5 => RollbackValidationRun::VERDICT_PARTIAL,
            default       => RollbackValidationRun::VERDICT_NOT_READY,
        };

        $run = new RollbackValidationRun([
            'run_id'                  => 'rvr-' . Str::random(32),
            'release_id'              => $releaseId ?: null,
            'rollback_target_version' => $rollbackTargetVersion,
            'migration_reversible'    => $migrationReversible,
            'schema_rollback_safe'    => $schemaRollbackSafe,
            'replay_safe'             => $replaySafe,
            'data_integrity_ok'       => $dataIntegrityOk,
            'readiness_score'         => round($score, 2),
            'verdict'                 => $verdict,
            'simulation_results'      => $simulationResults,
            'validated_by'            => $validatedBy,
        ]);
        $run->save();

        if ($releaseId) {
            $this->auditEvent($releaseId, 'rollback_validation_completed', $validatedBy, [
                'run_id'          => $run->run_id,
                'readiness_score' => $score,
                'verdict'         => $verdict,
            ]);
        }

        return $run;
    }

    // ─── Go / No-Go Approval ─────────────────────────────────────────────────

    /**
     * Create a release approval request.
     */
    public function createApprovalRequest(
        string $releaseId,
        string $requestedBy,
        string $readinessRunId = '',
        string $rollbackRunId = '',
        string $readinessSummary = '',
    ): ReleaseApprovalRequest {
        $request = new ReleaseApprovalRequest([
            'request_id'       => 'rar-' . Str::random(32),
            'release_id'       => $releaseId,
            'readiness_run_id' => $readinessRunId ?: null,
            'rollback_run_id'  => $rollbackRunId ?: null,
            'readiness_summary' => $readinessSummary,
            'status'           => ReleaseApprovalRequest::STATUS_PENDING,
            'requested_by'     => $requestedBy,
        ]);
        $request->save();

        $this->auditEvent($releaseId, 'approval_requested', $requestedBy, [
            'request_id' => $request->request_id,
        ]);

        return $request;
    }

    /**
     * Submit a go/no-go decision. Creator cannot self-approve.
     */
    public function submitGoNogo(
        string $requestId,
        string $decidedBy,
        string $decision,
        string $rationale = '',
    ): GoNogoDecision {
        $request = ReleaseApprovalRequest::where('request_id', $requestId)->firstOrFail();

        if ($request->requested_by === $decidedBy) {
            throw new \LogicException('Creator cannot self-approve a release. A different operator must decide.');
        }

        if (!in_array($decision, [GoNogoDecision::DECISION_GO, GoNogoDecision::DECISION_NO_GO], true)) {
            throw new \InvalidArgumentException("Decision must be 'go' or 'no_go'.");
        }

        $goNogo = new GoNogoDecision([
            'decision_id' => 'gng-' . Str::random(32),
            'request_id'  => $requestId,
            'release_id'  => $request->release_id,
            'decision'    => $decision,
            'rationale'   => $rationale,
            'decided_by'  => $decidedBy,
        ]);
        $goNogo->save();

        $this->auditEvent($request->release_id, 'gonogo_decision_submitted', $decidedBy, [
            'decision_id' => $goNogo->decision_id,
            'decision'    => $decision,
        ]);

        return $goNogo;
    }

    // ─── Runbook Governance ───────────────────────────────────────────────────

    /**
     * Create a new operational runbook (mutable record).
     */
    public function createRunbook(
        string $title,
        string $runbookType,
        string $owner,
        string $initialContent,
        string $authoredBy,
    ): OperationalRunbook {
        if (!in_array($runbookType, self::RUNBOOK_TYPES, true)) {
            throw new \InvalidArgumentException("Unknown runbook_type: {$runbookType}");
        }

        $runbook = OperationalRunbook::create([
            'runbook_id'      => 'rb-' . Str::random(32),
            'title'           => $title,
            'runbook_type'    => $runbookType,
            'current_version' => '1.0.0',
            'owner'           => $owner,
            'is_active'       => true,
        ]);

        $this->createRunbookVersion($runbook->runbook_id, '1.0.0', $initialContent, $authoredBy);

        return $runbook;
    }

    /**
     * Add an immutable version snapshot to a runbook.
     */
    public function createRunbookVersion(
        string $runbookId,
        string $version,
        string $content,
        string $authoredBy,
    ): OperationalRunbookVersion {
        $contentHash = hash('sha256', $content);

        $ver = new OperationalRunbookVersion([
            'version_id'  => 'rbv-' . Str::random(32),
            'runbook_id'  => $runbookId,
            'version'     => $version,
            'content'     => $content,
            'content_hash' => $contentHash,
            'authored_by' => $authoredBy,
        ]);
        $ver->save();

        return $ver;
    }

    // ─── Audit Trail ──────────────────────────────────────────────────────────

    private function auditEvent(
        string $releaseId,
        string $eventType,
        string $actor,
        array  $payload = [],
    ): void {
        $event = new ReleaseAuditEvent([
            'event_id'       => 'rae-' . Str::random(32),
            'release_id'     => $releaseId,
            'event_type'     => $eventType,
            'actor'          => $actor,
            'event_payload'  => $payload,
            'correlation_id' => $payload['run_id'] ?? $payload['request_id'] ?? null,
        ]);
        $event->save();
    }

    // ─── Dashboard Stats ──────────────────────────────────────────────────────

    public function getDashboardStats(): array
    {
        return [
            'total_manifests'          => ReleaseManifest::count(),
            'draft_manifests'          => ReleaseManifest::where('status', ReleaseManifest::STATUS_DRAFT)->count(),
            'total_readiness_runs'     => DeploymentReadinessRun::count(),
            'go_verdicts'              => DeploymentReadinessRun::where('overall_verdict', DeploymentReadinessRun::VERDICT_GO)->count(),
            'no_go_verdicts'           => DeploymentReadinessRun::where('overall_verdict', DeploymentReadinessRun::VERDICT_NO_GO)->count(),
            'total_drift_reports'      => EnvironmentDriftReport::count(),
            'blocking_drift_reports'   => EnvironmentDriftReport::where('is_blocking', true)->count(),
            'total_rollback_runs'      => RollbackValidationRun::count(),
            'ready_rollbacks'          => RollbackValidationRun::where('verdict', RollbackValidationRun::VERDICT_READY)->count(),
            'total_approval_requests'  => ReleaseApprovalRequest::count(),
            'pending_approvals'        => ReleaseApprovalRequest::where('status', ReleaseApprovalRequest::STATUS_PENDING)->count(),
            'total_gonogo_decisions'   => GoNogoDecision::count(),
            'go_decisions'             => GoNogoDecision::where('decision', GoNogoDecision::DECISION_GO)->count(),
            'no_go_decisions'          => GoNogoDecision::where('decision', GoNogoDecision::DECISION_NO_GO)->count(),
            'total_audit_events'       => ReleaseAuditEvent::count(),
            'total_runbooks'           => OperationalRunbook::count(),
            'active_runbooks'          => OperationalRunbook::where('is_active', true)->count(),
            'total_runbook_versions'   => OperationalRunbookVersion::count(),
            'advisory_only'            => true,
        ];
    }
}
