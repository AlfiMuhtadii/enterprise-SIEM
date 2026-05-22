<?php

namespace App\Http\Controllers\MultiTenant;

use App\Http\Controllers\Controller;
use App\Models\TenantIsolationAudit;
use App\Models\TenantContextPropagationRun;
use App\Models\TenantReplayValidationRun;
use App\Models\TenantGraphIsolationReport;
use App\Models\TenantExportValidationRun;
use App\Models\TenantNamespaceValidationReport;
use App\Models\TenantBoundaryViolationReport;
use App\Models\TenantReplayLineage;
use App\Models\TenantEvidenceIntegrityReport;
use App\Services\MultiTenantIsolationService;

class MultiTenantIsolationController extends Controller
{
    public function __construct(private MultiTenantIsolationService $service) {}

    public function isolationDashboard()
    {
        $stats         = $this->service->dashboardStats();
        $recentAudits  = TenantIsolationAudit::latest()->limit(20)->get();
        $violations    = TenantBoundaryViolationReport::latest()->limit(10)->get();
        return view('multi-tenant.isolation-dashboard', compact('stats', 'recentAudits', 'violations'));
    }

    public function replayValidation()
    {
        $runs = TenantReplayValidationRun::latest()->limit(50)->get();
        return view('multi-tenant.replay-validation', compact('runs'));
    }

    public function graphIsolation()
    {
        $reports = TenantGraphIsolationReport::latest()->limit(50)->get();
        return view('multi-tenant.graph-isolation', compact('reports'));
    }

    public function exportGovernance()
    {
        $runs = TenantExportValidationRun::latest()->limit(50)->get();
        return view('multi-tenant.export-governance', compact('runs'));
    }

    public function namespaceValidation()
    {
        $reports = TenantNamespaceValidationReport::latest()->limit(50)->get();
        return view('multi-tenant.namespace-validation', compact('reports'));
    }

    public function boundaryViolations()
    {
        $byType     = TenantBoundaryViolationReport::selectRaw('violation_type, count(*) as cnt')
            ->groupBy('violation_type')->pluck('cnt', 'violation_type');
        $bySeverity = TenantBoundaryViolationReport::selectRaw('severity, count(*) as cnt')
            ->groupBy('severity')->pluck('cnt', 'severity');
        $timeline   = TenantBoundaryViolationReport::latest()->limit(50)->get();
        return view('multi-tenant.boundary-violations', compact('byType', 'bySeverity', 'timeline'));
    }

    public function evidenceIntegrity()
    {
        $reports = TenantEvidenceIntegrityReport::latest()->limit(50)->get();
        $stats   = [
            'total'        => TenantEvidenceIntegrityReport::count(),
            'pass'         => TenantEvidenceIntegrityReport::where('verdict', 'pass')->count(),
            'fail'         => TenantEvidenceIntegrityReport::where('verdict', 'fail')->count(),
            'partial'      => TenantEvidenceIntegrityReport::where('verdict', 'partial')->count(),
            'cross_tenant' => TenantEvidenceIntegrityReport::where('cross_tenant_refs', '>', 0)->count(),
        ];
        return view('multi-tenant.evidence-integrity', compact('reports', 'stats'));
    }

    public function contextPropagation()
    {
        $runs = TenantContextPropagationRun::latest()->limit(50)->get();
        return view('multi-tenant.context-propagation', compact('runs'));
    }

    public function governanceDashboard()
    {
        $stats          = $this->service->dashboardStats();
        $lineage        = TenantReplayLineage::latest()->limit(20)->get();
        $contextRuns    = TenantContextPropagationRun::latest()->limit(10)->get();
        return view('multi-tenant.governance-dashboard', compact('stats', 'lineage', 'contextRuns'));
    }
}
