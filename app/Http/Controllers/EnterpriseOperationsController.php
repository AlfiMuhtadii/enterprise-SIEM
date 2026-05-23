<?php

namespace App\Http\Controllers;

use App\Models\OperationalRecoveryRun;
use App\Models\ServiceLifecycleAudit;
use App\Models\RecoveryCheckpointHistory;
use App\Models\FailoverValidationRun;
use App\Models\BoundedAutomationReport;
use App\Models\RecoveryDependencyGraph;
use App\Models\OperationalContinuityReport;
use App\Models\RecoverySimulationRun;
use App\Models\EnterpriseOperationsAudit;
use App\Services\EnterpriseOperationsAutomationService;
use Illuminate\View\View;

class EnterpriseOperationsController extends Controller
{
    public function __construct(private EnterpriseOperationsAutomationService $service) {}

    public function dashboard(): View
    {
        return view('enterprise-ops.dashboard', [
            'stats' => $this->service->dashboardStats(),
        ]);
    }

    public function recoveryOrchestration(): View
    {
        return view('enterprise-ops.recovery-orchestration', [
            'runs' => OperationalRecoveryRun::latest()->paginate(50),
        ]);
    }

    public function lifecycleExplorer(): View
    {
        return view('enterprise-ops.lifecycle-explorer', [
            'events' => ServiceLifecycleAudit::latest()->paginate(50),
        ]);
    }

    public function failoverGovernance(): View
    {
        return view('enterprise-ops.failover-governance', [
            'runs' => FailoverValidationRun::latest()->paginate(50),
        ]);
    }

    public function continuityDashboard(): View
    {
        return view('enterprise-ops.continuity-dashboard', [
            'reports' => OperationalContinuityReport::latest()->paginate(50),
        ]);
    }

    public function dependencyGraphViewer(): View
    {
        return view('enterprise-ops.dependency-graph', [
            'graphs' => RecoveryDependencyGraph::latest()->paginate(50),
        ]);
    }

    public function simulationTimeline(): View
    {
        return view('enterprise-ops.simulation-timeline', [
            'simulations' => RecoverySimulationRun::latest()->paginate(50),
        ]);
    }

    public function automationViewer(): View
    {
        return view('enterprise-ops.automation-viewer', [
            'reports' => BoundedAutomationReport::latest()->paginate(50),
        ]);
    }

    public function auditTimeline(): View
    {
        return view('enterprise-ops.audit-timeline', [
            'events' => EnterpriseOperationsAudit::latest()->paginate(50),
        ]);
    }
}
