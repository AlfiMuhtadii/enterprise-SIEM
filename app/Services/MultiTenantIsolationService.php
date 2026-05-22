<?php

namespace App\Services;

use App\Models\TenantIsolationAudit;
use App\Models\TenantContextPropagationRun;
use App\Models\TenantReplayValidationRun;
use App\Models\TenantGraphIsolationReport;
use App\Models\TenantExportValidationRun;
use App\Models\TenantNamespaceValidationReport;
use App\Models\TenantBoundaryViolationReport;
use App\Models\TenantReplayLineage;
use App\Models\TenantEvidenceIntegrityReport;
use Illuminate\Support\Str;

class MultiTenantIsolationService
{
    public const ADVISORY_ONLY = true;

    public const VALID_SCOPES = [
        'telemetry', 'graph', 'replay', 'export', 'hunt',
        'dashboard', 'soar', 'evidence', 'namespace',
    ];

    public const VALID_EXPORT_SCOPES = [
        'investigation', 'hunt', 'evidence', 'alerts', 'incidents',
    ];

    public const NAMESPACE_TYPES = ['cache', 'queue', 'index', 'spool', 'replay'];

    public const MAX_TRAVERSAL_DEPTH = 20;
    public const MAX_REPLAY_DEPTH    = 10;

    // =========================================================================
    // Isolation audit
    // =========================================================================

    public function runIsolationAudit(
        string $tenantId,
        string $scope,
        array  $findings = [],
        ?string $operatorId = null
    ): TenantIsolationAudit {
        if (!in_array($scope, self::VALID_SCOPES, true)) {
            throw new \InvalidArgumentException("Invalid isolation scope: {$scope}");
        }

        $checksTotal  = count($findings) ?: 1;
        $checksFailed = count(array_filter($findings, fn($f) => ($f['passed'] ?? true) === false));
        $checksPassed = $checksTotal - $checksFailed;
        $isolationOk  = $checksFailed === 0;
        $verdict      = $isolationOk ? 'pass' : ($checksPassed > 0 ? 'partial' : 'fail');

        return TenantIsolationAudit::create([
            'audit_id'      => 'tia-' . Str::uuid(),
            'tenant_id'     => $tenantId,
            'scope'         => $scope,
            'verdict'       => $verdict,
            'isolation_ok'  => $isolationOk,
            'checks_total'  => $checksTotal,
            'checks_passed' => $checksPassed,
            'checks_failed' => $checksFailed,
            'findings'      => $findings ?: null,
            'operator_id'   => $operatorId,
            'is_advisory'   => true,
        ]);
    }

    // =========================================================================
    // Context propagation
    // =========================================================================

    public function validateContextPropagation(
        string $tenantId,
        string $traceId,
        array  $contextFrames
    ): TenantContextPropagationRun {
        $hopsTotal   = count($contextFrames);
        $failures    = 0;
        $hopsOk      = 0;

        foreach ($contextFrames as $frame) {
            $frameTenant = $frame['tenant_id'] ?? null;
            if ($frameTenant !== $tenantId) {
                $failures++;
            } else {
                $hopsOk++;
            }
        }

        $contextOk     = $failures === 0;
        $failureReason = $contextOk ? null
            : "{$failures} hop(s) had mismatched tenant attribution";

        return TenantContextPropagationRun::create([
            'run_id'                => 'tcp-' . Str::uuid(),
            'tenant_id'             => $tenantId,
            'trace_id'              => $traceId,
            'context_ok'            => $contextOk,
            'hops_total'            => $hopsTotal,
            'hops_validated'        => $hopsOk,
            'attribution_failures'  => $failures,
            'context_frames'        => $contextFrames ?: null,
            'failure_reason'        => $failureReason,
            'is_advisory'           => true,
        ]);
    }

    // =========================================================================
    // Replay validation
    // =========================================================================

    public function validateReplay(
        string $tenantId,
        string $replayId,
        int    $eventsReplayed,
        int    $crossTenantDetected = 0,
        bool   $orderingDeterministic = true,
        array  $lineageRefs = []
    ): TenantReplayValidationRun {
        $replayIsolated = $crossTenantDetected === 0;
        $verdict = match (true) {
            $crossTenantDetected > 0  => 'contaminated',
            !$orderingDeterministic   => 'fail',
            default                   => 'pass',
        };

        return TenantReplayValidationRun::create([
            'validation_id'          => 'trv-' . Str::uuid(),
            'tenant_id'              => $tenantId,
            'replay_id'              => $replayId,
            'replay_isolated'        => $replayIsolated,
            'ordering_deterministic' => $orderingDeterministic,
            'events_replayed'        => $eventsReplayed,
            'cross_tenant_detected'  => $crossTenantDetected,
            'verdict'                => $verdict,
            'lineage_refs'           => $lineageRefs ?: null,
            'is_advisory'            => true,
        ]);
    }

    // =========================================================================
    // Graph isolation
    // =========================================================================

    public function validateGraphIsolation(
        string $tenantId,
        string $graphId,
        int    $nodesValidated,
        int    $edgesValidated,
        int    $crossTenantEdges    = 0,
        int    $sharedEvidence      = 0,
        int    $traversalDepth      = 0
    ): TenantGraphIsolationReport {
        if ($traversalDepth > self::MAX_TRAVERSAL_DEPTH) {
            throw new \OverflowException(
                "Graph traversal depth {$traversalDepth} exceeds MAX_TRAVERSAL_DEPTH=" . self::MAX_TRAVERSAL_DEPTH
            );
        }

        $isolationOk = $crossTenantEdges === 0 && $sharedEvidence === 0;
        $verdict = match (true) {
            $crossTenantEdges > 0 || $sharedEvidence > 0 => 'fail',
            $nodesValidated === 0 && $edgesValidated === 0 => 'partial',
            default => 'pass',
        };

        return TenantGraphIsolationReport::create([
            'report_id'                   => 'tgi-' . Str::uuid(),
            'tenant_id'                   => $tenantId,
            'graph_id'                    => $graphId,
            'isolation_ok'                => $isolationOk,
            'nodes_validated'             => $nodesValidated,
            'edges_validated'             => $edgesValidated,
            'cross_tenant_edges_detected' => $crossTenantEdges,
            'shared_evidence_detected'    => $sharedEvidence,
            'traversal_depth'             => $traversalDepth,
            'verdict'                     => $verdict,
            'is_advisory'                 => true,
        ]);
    }

    // =========================================================================
    // Export validation
    // =========================================================================

    public function validateExport(
        string   $tenantId,
        string   $exportId,
        string   $exportScope,
        string   $checksum,
        bool     $approvalRequired = true,
        ?string  $approvedBy = null,
        ?\Carbon\Carbon $expiresAt = null
    ): TenantExportValidationRun {
        if (!in_array($exportScope, self::VALID_EXPORT_SCOPES, true)) {
            throw new \InvalidArgumentException("Invalid export scope: {$exportScope}");
        }

        $scopeOk     = true;
        $integrityOk = strlen($checksum) === 64; // sha256 hex
        $approved    = $approvedBy !== null;
        $expired     = $expiresAt && $expiresAt->isPast();
        $verdict = match (true) {
            $expired          => 'expired',
            !$integrityOk     => 'fail',
            !$scopeOk         => 'fail',
            default           => 'pass',
        };

        return TenantExportValidationRun::create([
            'validation_id'    => 'tev-' . Str::uuid(),
            'tenant_id'        => $tenantId,
            'export_id'        => $exportId,
            'export_scope'     => $exportScope,
            'scope_ok'         => $scopeOk,
            'integrity_ok'     => $integrityOk,
            'checksum'         => $checksum,
            'approval_required'=> $approvalRequired,
            'approved'         => $approved,
            'approved_by'      => $approvedBy,
            'expires_at'       => $expiresAt,
            'verdict'          => $verdict,
            'is_advisory'      => true,
        ]);
    }

    // =========================================================================
    // Namespace validation
    // =========================================================================

    public function validateNamespaces(
        string $tenantId,
        array  $namespaces
    ): TenantNamespaceValidationReport {
        $valid     = 0;
        $invalid   = [];
        $crossover = false;

        foreach ($namespaces as $ns) {
            $type  = $ns['type']  ?? '';
            $key   = $ns['key']   ?? '';
            $owner = $ns['owner'] ?? $tenantId;

            if (!in_array($type, self::NAMESPACE_TYPES, true)) {
                $invalid[] = $ns;
                continue;
            }

            if ($owner !== $tenantId) {
                $invalid[]  = $ns;
                $crossover  = true;
                continue;
            }

            if (!str_contains($key, $tenantId)) {
                $invalid[] = $ns;
                continue;
            }

            $valid++;
        }

        return TenantNamespaceValidationReport::create([
            'report_id'          => 'tns-' . Str::uuid(),
            'tenant_id'          => $tenantId,
            'namespaces_checked' => $namespaces,
            'namespaces_valid'   => $valid,
            'namespaces_invalid' => count($invalid),
            'crossover_detected' => $crossover,
            'invalid_namespaces' => $invalid ?: null,
            'is_advisory'        => true,
        ]);
    }

    // =========================================================================
    // Boundary violation
    // =========================================================================

    public function recordBoundaryViolation(
        string  $tenantId,
        string  $violationType,
        string  $description,
        string  $severity = 'medium',
        ?string $sourceTenantId = null,
        ?string $targetTenantId = null,
        array   $evidenceRefs = []
    ): TenantBoundaryViolationReport {
        if (!in_array($violationType, TenantBoundaryViolationReport::VIOLATION_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid violation type: {$violationType}");
        }

        if (!in_array($severity, TenantBoundaryViolationReport::SEVERITIES, true)) {
            throw new \InvalidArgumentException("Invalid severity: {$severity}");
        }

        return TenantBoundaryViolationReport::create([
            'report_id'        => 'tbv-' . Str::uuid(),
            'tenant_id'        => $tenantId,
            'violation_type'   => $violationType,
            'source_tenant_id' => $sourceTenantId,
            'target_tenant_id' => $targetTenantId,
            'description'      => $description,
            'severity'         => $severity,
            'evidence_refs'    => $evidenceRefs ?: null,
            'is_advisory'      => true,
        ]);
    }

    // =========================================================================
    // Replay lineage
    // =========================================================================

    public function trackReplayLineage(
        string  $tenantId,
        string  $replayId,
        string  $originTenantId,
        int     $replayDepth = 0,
        ?string $parentReplayId = null,
        array   $lineageChain = []
    ): TenantReplayLineage {
        if ($replayDepth > self::MAX_REPLAY_DEPTH) {
            throw new \OverflowException(
                "Replay depth {$replayDepth} exceeds MAX_REPLAY_DEPTH=" . self::MAX_REPLAY_DEPTH
            );
        }

        $lineageClean = $originTenantId === $tenantId;

        return TenantReplayLineage::create([
            'lineage_id'      => 'trl-' . Str::uuid(),
            'tenant_id'       => $tenantId,
            'replay_id'       => $replayId,
            'parent_replay_id'=> $parentReplayId,
            'origin_tenant_id'=> $originTenantId,
            'replay_depth'    => $replayDepth,
            'lineage_chain'   => $lineageChain ?: null,
            'lineage_clean'   => $lineageClean,
            'is_advisory'     => true,
        ]);
    }

    // =========================================================================
    // Evidence integrity
    // =========================================================================

    public function validateEvidenceIntegrity(
        string $tenantId,
        array  $evidenceRefs
    ): TenantEvidenceIntegrityReport {
        $checked        = count($evidenceRefs);
        $ok             = 0;
        $failed         = 0;
        $crossTenant    = 0;
        $failedRefs     = [];

        foreach ($evidenceRefs as $ref) {
            $refTenant = $ref['tenant_id'] ?? null;
            $hash      = $ref['hash']      ?? null;

            if ($refTenant !== $tenantId) {
                $crossTenant++;
                $failedRefs[] = $ref;
                $failed++;
                continue;
            }

            if (empty($hash)) {
                $failedRefs[] = $ref;
                $failed++;
                continue;
            }

            $ok++;
        }

        $verdict = match (true) {
            $crossTenant > 0  => 'fail',
            $failed > 0       => 'partial',
            default           => 'pass',
        };

        return TenantEvidenceIntegrityReport::create([
            'report_id'             => 'tei-' . Str::uuid(),
            'tenant_id'             => $tenantId,
            'evidence_refs_checked' => $checked,
            'integrity_ok'          => $ok,
            'integrity_failed'      => $failed,
            'cross_tenant_refs'     => $crossTenant,
            'verdict'               => $verdict,
            'failed_refs'           => $failedRefs ?: null,
            'is_advisory'           => true,
        ]);
    }

    // =========================================================================
    // Dashboard aggregation
    // =========================================================================

    public function dashboardStats(): array
    {
        return [
            'total_isolation_audits'     => TenantIsolationAudit::count(),
            'isolation_failures'         => TenantIsolationAudit::where('isolation_ok', false)->count(),
            'boundary_violations'        => TenantBoundaryViolationReport::count(),
            'critical_violations'        => TenantBoundaryViolationReport::where('severity', 'critical')->count(),
            'replay_contaminations'      => TenantReplayValidationRun::where('verdict', 'contaminated')->count(),
            'graph_isolation_failures'   => TenantGraphIsolationReport::where('isolation_ok', false)->count(),
            'export_validation_runs'     => TenantExportValidationRun::count(),
            'export_failures'            => TenantExportValidationRun::where('verdict', 'fail')->count(),
            'namespace_crossovers'       => TenantNamespaceValidationReport::where('crossover_detected', true)->count(),
            'evidence_integrity_failures'=> TenantEvidenceIntegrityReport::where('verdict', 'fail')->count(),
            'context_propagation_failures'=> TenantContextPropagationRun::where('context_ok', false)->count(),
            'replay_lineage_contaminated'=> TenantReplayLineage::where('lineage_clean', false)->count(),
        ];
    }
}
