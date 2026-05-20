<?php

namespace App\Http\Controllers\Governance;

use App\Http\Controllers\Controller;
use App\Models\AuditExportRequest;
use App\Models\EvidenceIntegrityFailure;
use App\Models\EvidenceIntegrityRun;
use App\Models\GovernanceAccessReview;
use App\Models\GovernanceReviewFinding;
use App\Models\PiiAccessAudit;
use App\Models\RetentionPolicyVersion;
use App\Models\TenantIsolationValidationRun;
use App\Services\ComplianceGovernanceService;
use Illuminate\View\View;

/**
 * Compliance, Governance & Evidence Integrity UI controller.
 * Advisory-only — no autonomous remediation or destructive evidence mutation.
 */
class ComplianceGovernanceController extends Controller
{
    public function __construct(
        private ComplianceGovernanceService $governance,
    ) {}

    public function evidenceIntegrityDashboard(): View
    {
        $stats      = $this->governance->getDashboardStats();
        $recentRuns = EvidenceIntegrityRun::orderByDesc('created_at')->limit(20)->get();
        return view('governance.evidence-integrity-dashboard', compact('stats', 'recentRuns'));
    }

    public function retentionGovernanceExplorer(): View
    {
        $versions = RetentionPolicyVersion::orderByDesc('created_at')->limit(100)->get();
        $pending  = $versions->where('review_status', 'pending');
        return view('governance.retention-governance-explorer', compact('versions', 'pending'));
    }

    public function auditExportWorkflow(): View
    {
        $requests = AuditExportRequest::orderByDesc('created_at')->limit(100)->get();
        $expiring = $this->governance->getExpiringExports(7);
        return view('governance.audit-export-workflow', compact('requests', 'expiring'));
    }

    public function tenantIsolationValidator(): View
    {
        $runs        = TenantIsolationValidationRun::orderByDesc('created_at')->limit(100)->get();
        $violations  = $runs->where('violations_found', '>', 0);
        $checkTypes  = TenantIsolationValidationRun::CHECK_TYPES;
        return view('governance.tenant-isolation-validator', compact('runs', 'violations', 'checkTypes'));
    }

    public function piiAccessAuditViewer(): View
    {
        $accesses     = PiiAccessAudit::orderByDesc('created_at')->limit(200)->get();
        $unmasked     = $accesses->where('was_masked', false);
        $byCategory   = $accesses->groupBy('pii_category');
        return view('governance.pii-access-audit-viewer', compact('accesses', 'unmasked', 'byCategory'));
    }

    public function governanceAccessReviewConsole(): View
    {
        $reviews  = GovernanceAccessReview::orderByDesc('created_at')->limit(100)->get();
        $stale    = $this->governance->getStaleReviews();
        $open     = $reviews->whereIn('review_status', ['open', 'in_review']);
        return view('governance.governance-access-review-console', compact('reviews', 'stale', 'open'));
    }

    public function complianceReportingDashboard(): View
    {
        $stats    = $this->governance->getDashboardStats();
        $findings = GovernanceReviewFinding::orderByDesc('created_at')->limit(50)->get();
        return view('governance.compliance-reporting-dashboard', compact('stats', 'findings'));
    }

    public function integrityFailureTimeline(): View
    {
        $failures = EvidenceIntegrityFailure::orderByDesc('created_at')->limit(200)->get();
        $byType   = $failures->groupBy('failure_type');
        return view('governance.integrity-failure-timeline', compact('failures', 'byType'));
    }

    public function governanceFindingsExplorer(): View
    {
        $findings  = GovernanceReviewFinding::orderByDesc('created_at')->limit(200)->get();
        $critical  = $findings->where('severity', 'critical');
        $bySeverity= $findings->groupBy('severity');
        return view('governance.governance-findings-explorer', compact('findings', 'critical', 'bySeverity'));
    }
}
