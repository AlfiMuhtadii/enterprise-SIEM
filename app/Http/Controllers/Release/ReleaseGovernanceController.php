<?php

namespace App\Http\Controllers\Release;

use App\Http\Controllers\Controller;
use App\Models\DeploymentReadinessRun;
use App\Models\EnvironmentDriftReport;
use App\Models\GoNogoDecision;
use App\Models\OperationalRunbook;
use App\Models\OperationalRunbookVersion;
use App\Models\ReleaseApprovalRequest;
use App\Models\ReleaseAuditEvent;
use App\Models\ReleaseManifest;
use App\Models\RollbackValidationRun;
use App\Services\ReleaseGovernanceService;
use Illuminate\Http\Request;

class ReleaseGovernanceController extends Controller
{
    public function __construct(private ReleaseGovernanceService $svc) {}

    public function releaseDashboard(Request $request)
    {
        $stats = $this->svc->getDashboardStats();
        $recentManifests = ReleaseManifest::latest()->limit(10)->get();
        $recentReadiness = DeploymentReadinessRun::latest()->limit(10)->get();
        return view('release.release-dashboard', compact('stats', 'recentManifests', 'recentReadiness'));
    }

    public function releaseManifestExplorer(Request $request)
    {
        $manifests = ReleaseManifest::latest()->limit(50)->get();
        return view('release.release-manifest-explorer', compact('manifests'));
    }

    public function deploymentReadinessViewer(Request $request)
    {
        $runs = DeploymentReadinessRun::latest()->limit(50)->get();
        return view('release.deployment-readiness-viewer', compact('runs'));
    }

    public function environmentDriftTimeline(Request $request)
    {
        $reports = EnvironmentDriftReport::latest()->limit(50)->get();
        $byType  = $reports->groupBy('drift_type');
        return view('release.environment-drift-timeline', compact('reports', 'byType'));
    }

    public function rollbackReadinessConsole(Request $request)
    {
        $runs = RollbackValidationRun::latest()->limit(50)->get();
        return view('release.rollback-readiness-console', compact('runs'));
    }

    public function goNogoApprovalWorkflow(Request $request)
    {
        $requests  = ReleaseApprovalRequest::latest()->limit(50)->get();
        $decisions = GoNogoDecision::latest()->limit(50)->get();
        return view('release.gonogo-approval-workflow', compact('requests', 'decisions'));
    }

    public function runbookExplorer(Request $request)
    {
        $runbooks = OperationalRunbook::latest()->limit(50)->get();
        $types    = ReleaseGovernanceService::RUNBOOK_TYPES;
        return view('release.runbook-explorer', compact('runbooks', 'types'));
    }

    public function releaseAuditTimeline(Request $request)
    {
        $events = ReleaseAuditEvent::latest()->limit(100)->get();
        return view('release.release-audit-timeline', compact('events'));
    }

    public function deploymentSafetyDashboard(Request $request)
    {
        $driftCount    = EnvironmentDriftReport::where('is_blocking', true)->count();
        $goCount       = GoNogoDecision::where('decision', GoNogoDecision::DECISION_GO)->count();
        $noGoCount     = GoNogoDecision::where('decision', GoNogoDecision::DECISION_NO_GO)->count();
        $pendingCount  = ReleaseApprovalRequest::where('status', ReleaseApprovalRequest::STATUS_PENDING)->count();
        $readyRollback = RollbackValidationRun::where('verdict', RollbackValidationRun::VERDICT_READY)->count();
        return view('release.deployment-safety-dashboard', compact(
            'driftCount', 'goCount', 'noGoCount', 'pendingCount', 'readyRollback'
        ));
    }
}
