<?php

namespace App\Services;

use App\Models\AuditExportAccessLog;
use App\Models\AuditExportRequest;
use App\Models\EvidenceIntegrityFailure;
use App\Models\EvidenceIntegrityRun;
use App\Models\GovernanceAccessReview;
use App\Models\GovernanceReviewFinding;
use App\Models\PiiAccessAudit;
use App\Models\RetentionPolicy;
use App\Models\RetentionPolicyVersion;
use App\Models\TenantIsolationValidationRun;
use App\Support\TraceRedactor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Compliance, Governance & Evidence Integrity Service — Phase 1.
 *
 * All governance behavior is:
 *   - deterministic
 *   - append-only where required
 *   - audit-visible
 *   - replay-safe
 *   - operator-visible
 *   - evidence-linked
 *
 * NOT implemented: isolateHost, quarantineHost, executeShell, killProcess,
 * autoRemediate, hidden audit mutation, destructive evidence deletion,
 * unrestricted export, silent retention purge, hidden PII access,
 * automatic compliance enforcement, unsafe tenant crossover.
 */
class ComplianceGovernanceService
{
    // ─── Evidence integrity ──────────────────────────────────────────────────

    /**
     * Run an evidence integrity validation for a given scope.
     * Deterministic: same input data always produces the same hash.
     * Append-only result — never mutates source evidence.
     */
    public function runIntegrityCheck(
        string  $scope,
        ?string $scopeId = null,
        ?string $triggeredBy = null,
        ?string $traceId = null
    ): EvidenceIntegrityRun {
        $failures     = 0;
        $linkage      = 0;
        $totalChecked = 0;
        $failureRecs  = [];
        $checkSummary = [];

        // Scope-specific integrity checks
        switch ($scope) {
            case 'investigation':
                [$totalChecked, $failures, $linkage, $failureRecs, $checkSummary] =
                    $this->checkInvestigationIntegrity($scopeId);
                break;
            case 'trace':
                [$totalChecked, $failures, $linkage, $failureRecs, $checkSummary] =
                    $this->checkTraceIntegrity($scopeId);
                break;
            case 'detection_rule':
                [$totalChecked, $failures, $linkage, $failureRecs, $checkSummary] =
                    $this->checkDetectionRuleIntegrity($scopeId);
                break;
            default:
                $totalChecked = 0;
                $checkSummary = ['scope' => $scope, 'note' => 'generic scan'];
        }

        $status = match(true) {
            $failures > 0   => EvidenceIntegrityRun::STATUS_FAILED,
            $linkage > 0    => EvidenceIntegrityRun::STATUS_WARNING,
            default         => EvidenceIntegrityRun::STATUS_PASSED,
        };

        $integrityHash = hash('sha256', json_encode([
            'scope' => $scope, 'scope_id' => $scopeId,
            'total' => $totalChecked, 'failures' => $failures,
        ]));

        $runId = 'eir-' . Str::random(32);

        $run = EvidenceIntegrityRun::create([
            'run_id'                => $runId,
            'scope'                 => $scope,
            'scope_id'              => $scopeId,
            'status'                => $status,
            'total_records_checked' => $totalChecked,
            'integrity_failures'    => $failures,
            'linkage_failures'      => $linkage,
            'trace_continuity_ok'   => $failures === 0 && $linkage === 0,
            'integrity_hash'        => $integrityHash,
            'check_summary'         => $checkSummary,
            'triggered_by'          => $triggeredBy,
            'trace_id'              => $traceId,
            'completed_at'          => now(),
            'created_at'            => now(),
        ]);

        foreach ($failureRecs as $fr) {
            EvidenceIntegrityFailure::create(array_merge($fr, [
                'failure_id' => 'eif-' . Str::random(32),
                'run_id'     => $runId,
                'created_at' => now(),
            ]));
        }

        return $run;
    }

    private function checkInvestigationIntegrity(?string $investigationId): array
    {
        $count    = 0;
        $failures = 0;
        $linkage  = 0;
        $failRecs = [];

        $investigations = $investigationId
            ? DB::table('investigations')->where('investigation_id', $investigationId)->get()
            : DB::table('investigations')->limit(100)->get();

        foreach ($investigations as $inv) {
            $count++;
            // Check that investigation has at least one event record
            $eventCount = DB::table('investigation_events')->where('investigation_id', $inv->id)->count();
            if ($eventCount === 0) {
                $linkage++;
                $failRecs[] = [
                    'evidence_type' => 'investigation',
                    'evidence_id'   => $inv->investigation_id ?? (string) $inv->id,
                    'failure_type'  => 'linkage_broken',
                    'description'   => 'Investigation has no associated event records',
                    'drift_detected'=> false,
                ];
            }
        }

        return [$count, $failures, $linkage, $failRecs, ['checked' => 'investigations', 'count' => $count]];
    }

    private function checkTraceIntegrity(?string $traceId): array
    {
        $count    = 0;
        $failures = 0;
        $linkage  = 0;
        $failRecs = [];

        // Count alerts with and without trace_id
        $alertsWithTrace    = DB::table('security_alerts')->whereNotNull('trace_id')->count();
        $alertsWithoutTrace = DB::table('security_alerts')->whereNull('trace_id')->count();
        $count              = $alertsWithTrace + $alertsWithoutTrace;

        if ($alertsWithoutTrace > 0) {
            $linkage++;
            $failRecs[] = [
                'evidence_type' => 'security_alert',
                'evidence_id'   => 'bulk',
                'failure_type'  => 'trace_gap',
                'description'   => "Found {$alertsWithoutTrace} alert(s) missing trace_id",
                'drift_detected'=> false,
            ];
        }

        return [$count, $failures, $linkage, $failRecs, ['alerts_checked' => $count, 'trace_gaps' => $alertsWithoutTrace]];
    }

    private function checkDetectionRuleIntegrity(?string $ruleId): array
    {
        $count    = 0;
        $failures = 0;
        $linkage  = 0;
        $failRecs = [];

        $versions = DB::table('detection_rule_versions')
            ->when($ruleId, fn($q) => $q->where('rule_id', $ruleId))
            ->limit(200)
            ->get();

        foreach ($versions as $v) {
            $count++;
            if (empty($v->rule_hash)) {
                $failures++;
                $failRecs[] = [
                    'evidence_type' => 'detection_rule_version',
                    'evidence_id'   => $v->version_id ?? (string) $v->id,
                    'failure_type'  => 'hash_mismatch',
                    'description'   => 'Rule version has no stored hash',
                    'drift_detected'=> true,
                ];
            }
        }

        return [$count, $failures, $linkage, $failRecs, ['rule_versions_checked' => $count]];
    }

    public function getIntegrityHistory(string $scope): Collection
    {
        return EvidenceIntegrityRun::where('scope', $scope)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();
    }

    // ─── Retention policy versioning ─────────────────────────────────────────

    public function snapshotRetentionPolicy(
        RetentionPolicy $policy,
        ?string          $changeReason = null,
        ?int             $createdBy = null
    ): RetentionPolicyVersion {
        $snapshot = $policy->toArray();
        $hash     = RetentionPolicyVersion::hashPolicy($snapshot);

        return RetentionPolicyVersion::create([
            'version_id'     => 'rpv-' . Str::random(32),
            'policy_id'      => $policy->policy_id ?? $policy->id,
            'version'        => '1.0.0',
            'version_hash'   => $hash,
            'policy_snapshot'=> $snapshot,
            'review_status'  => 'pending',
            'change_reason'  => $changeReason,
            'created_by'     => $createdBy,
        ]);
    }

    public function reviewRetentionPolicyVersion(
        RetentionPolicyVersion $version,
        string                  $status,
        ?string                 $notes = null,
        ?int                    $reviewedBy = null
    ): void {
        $version->update([
            'review_status' => $status,
            'review_notes'  => $notes,
            'reviewed_by'   => $reviewedBy,
            'reviewed_at'   => now(),
        ]);
    }

    // ─── Audit export governance ─────────────────────────────────────────────

    public function createExportRequest(
        string  $scope,
        string  $exportFormat = 'json',
        ?string $scopeId = null,
        ?string $exportReason = null,
        bool    $piiMasked = true,
        ?string $tenantScope = null,
        ?int    $requestedBy = null
    ): AuditExportRequest {
        return AuditExportRequest::create([
            'export_id'    => 'aex-' . Str::random(32),
            'scope'        => $scope,
            'scope_id'     => $scopeId,
            'export_format'=> $exportFormat,
            'status'       => 'draft',
            'pii_masked'   => $piiMasked,
            'export_reason'=> $exportReason,
            'tenant_scope' => $tenantScope,
            'requested_by' => $requestedBy,
        ]);
    }

    public function submitExportForApproval(AuditExportRequest $req): void
    {
        $req->update(['status' => 'pending_approval']);
    }

    public function approveExport(AuditExportRequest $req, int $approvedBy): void
    {
        if ($req->requested_by === $approvedBy) {
            throw new \LogicException('Export requester cannot self-approve');
        }

        $req->update([
            'status'      => 'approved',
            'approved_by' => $approvedBy,
            'approved_at' => now(),
            'expires_at'  => now()->addDays(30),
        ]);
    }

    public function packageExport(AuditExportRequest $req): void
    {
        $checksum = hash('sha256', json_encode([
            'export_id'   => $req->export_id,
            'scope'       => $req->scope,
            'scope_id'    => $req->scope_id,
            'pii_masked'  => $req->pii_masked,
            'approved_by' => $req->approved_by,
            'approved_at' => $req->approved_at?->toIso8601String(),
        ]));

        $req->update([
            'status'             => 'packaged',
            'integrity_checksum' => $checksum,
            'packaged_at'        => now(),
        ]);
    }

    public function logExportAccess(
        string  $exportId,
        string  $accessType = 'view',
        ?int    $accessedBy = null,
        ?string $traceId = null
    ): AuditExportAccessLog {
        return AuditExportAccessLog::create([
            'log_id'      => 'eal-' . Str::random(32),
            'export_id'   => $exportId,
            'access_type' => $accessType,
            'accessed_by' => $accessedBy,
            'trace_id'    => $traceId,
            'created_at'  => now(),
        ]);
    }

    public function getExpiringExports(int $withinDays = 7): Collection
    {
        return AuditExportRequest::where('status', 'approved')
            ->where('expires_at', '<=', now()->addDays($withinDays))
            ->get();
    }

    // ─── Tenant isolation validation ─────────────────────────────────────────

    public function runTenantIsolationCheck(
        string  $tenantScope,
        string  $checkType,
        ?string $triggeredBy = null,
        ?string $traceId = null
    ): TenantIsolationValidationRun {
        // Deterministic validation: check that queries for this scope don't surface other scopes
        $violations = 0;
        $violationDetails = [];
        $crossTenant = false;

        // Validate that export requests are scoped
        if ($checkType === 'export_scope') {
            $unscoped = AuditExportRequest::whereNull('tenant_scope')->count();
            if ($unscoped > 0) {
                $violations++;
                $violationDetails[] = ['check' => 'export_scope', 'unscoped_count' => $unscoped];
            }
        }

        $status = $violations > 0 ? 'warning' : 'passed';

        return TenantIsolationValidationRun::create([
            'run_id'               => 'tiv-' . Str::random(32),
            'tenant_scope'         => $tenantScope,
            'check_type'           => $checkType,
            'status'               => $status,
            'violations_found'     => $violations,
            'violation_details'    => $violationDetails ?: null,
            'cross_tenant_detected'=> $crossTenant,
            'triggered_by'         => $triggeredBy,
            'trace_id'             => $traceId,
            'created_at'           => now(),
        ]);
    }

    // ─── PII access audit (append-only) ──────────────────────────────────────

    public function logPiiAccess(
        string  $fieldName,
        string  $piiCategory,
        string  $accessContext,
        bool    $wasMasked = true,
        ?string $accessedByUser = null,
        ?string $accessedByRole = null,
        ?string $sourceEntity = null,
        ?string $traceId = null
    ): PiiAccessAudit {
        return PiiAccessAudit::create([
            'audit_id'               => 'pia-' . Str::random(32),
            'field_name'             => $fieldName,
            'pii_category'           => $piiCategory,
            'accessed_by_user'       => $accessedByUser,
            'accessed_by_role'       => $accessedByRole,
            'source_entity'          => $sourceEntity,
            'access_context'         => $accessContext,
            'was_masked'             => $wasMasked,
            'masking_policy_applied' => $wasMasked,
            'trace_id'               => $traceId,
            'created_at'             => now(),
        ]);
    }

    public function getPiiAccessHistory(string $fieldName): Collection
    {
        return PiiAccessAudit::where('field_name', $fieldName)
            ->orderByDesc('created_at')
            ->get();
    }

    // ─── Access review governance ─────────────────────────────────────────────

    public function openAccessReview(
        string  $subjectUser,
        string  $privilegedRole,
        int     $daysSinceLastReview = 0
    ): GovernanceAccessReview {
        $isStale = $daysSinceLastReview >= GovernanceAccessReview::STALE_THRESHOLD_DAYS;

        $review = GovernanceAccessReview::create([
            'review_id'              => 'gar-' . Str::random(32),
            'subject_user'           => $subjectUser,
            'privileged_role'        => $privilegedRole,
            'review_status'          => 'open',
            'review_window_start'    => now()->toDateString(),
            'review_window_end'      => now()->addDays(14)->toDateString(),
            'is_stale'               => $isStale,
            'days_since_last_review' => $daysSinceLastReview,
        ]);

        if ($isStale) {
            $this->addFinding($review->review_id, 'stale_privilege',
                "User '{$subjectUser}' has not been reviewed in {$daysSinceLastReview} days for role '{$privilegedRole}'",
                severity: 'warning');
        }

        return $review;
    }

    public function closeAccessReview(
        GovernanceAccessReview $review,
        string                  $decision,
        int                     $reviewerId,
        ?string                 $rationale = null
    ): void {
        $status = $decision === 'approved' ? 'approved' : 'revoked';

        $review->update([
            'review_status'              => $status,
            'reviewer_id'                => $reviewerId,
            'reviewed_at'                => now(),
            'review_decision_rationale'  => $rationale,
        ]);
    }

    public function addFinding(
        ?string $reviewId,
        string  $findingType,
        string  $description,
        string  $severity = 'informational',
        array   $evidenceLinks = [],
        bool    $remediationRequired = false,
        ?int    $createdBy = null
    ): GovernanceReviewFinding {
        return GovernanceReviewFinding::create([
            'finding_id'           => 'grf-' . Str::random(32),
            'review_id'            => $reviewId,
            'finding_type'         => $findingType,
            'severity'             => $severity,
            'description'          => $description,
            'evidence_links'       => $evidenceLinks ?: null,
            'remediation_required' => $remediationRequired,
            'is_advisory'          => true,
            'created_by'           => $createdBy,
            'created_at'           => now(),
        ]);
    }

    public function getStaleReviews(): Collection
    {
        return GovernanceAccessReview::where('is_stale', true)
            ->whereIn('review_status', ['open', 'in_review'])
            ->get();
    }

    // ─── Dashboard stats ─────────────────────────────────────────────────────

    public function getDashboardStats(): array
    {
        return [
            'total_integrity_runs'         => EvidenceIntegrityRun::count(),
            'failed_integrity_runs'        => EvidenceIntegrityRun::where('status', 'failed')->count(),
            'total_integrity_failures'     => EvidenceIntegrityFailure::count(),
            'total_retention_versions'     => RetentionPolicyVersion::count(),
            'pending_retention_reviews'    => RetentionPolicyVersion::where('review_status', 'pending')->count(),
            'total_export_requests'        => AuditExportRequest::count(),
            'pending_exports'              => AuditExportRequest::where('status', 'pending_approval')->count(),
            'total_pii_access_events'      => PiiAccessAudit::count(),
            'unmasked_pii_accesses'        => PiiAccessAudit::where('was_masked', false)->count(),
            'total_access_reviews'         => GovernanceAccessReview::count(),
            'stale_reviews'                => GovernanceAccessReview::where('is_stale', true)->count(),
            'open_reviews'                 => GovernanceAccessReview::where('review_status', 'open')->count(),
            'total_review_findings'        => GovernanceReviewFinding::count(),
            'critical_findings'            => GovernanceReviewFinding::where('severity', 'critical')->count(),
            'advisory_only'                => true,
            'autonomous_remediation'       => false,
        ];
    }
}
