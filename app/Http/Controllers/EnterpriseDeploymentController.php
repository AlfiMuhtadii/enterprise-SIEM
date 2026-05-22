<?php

namespace App\Http\Controllers;

use App\Models\DeploymentPackageManifest;
use App\Models\DeploymentIntegrityReport;
use App\Models\RolloutValidationRun;
use App\Models\DeploymentUpgradeHistory;
use App\Models\EnvironmentValidationReport;
use App\Models\DeploymentDriftReport;
use App\Models\DeploymentObservabilitySnapshot;
use App\Models\RolloutCheckpointHistory;
use App\Models\EnterpriseDeploymentAudit;
use App\Services\EnterpriseDeploymentHardeningService;
use Illuminate\View\View;

class EnterpriseDeploymentController extends Controller
{
    public function __construct(private EnterpriseDeploymentHardeningService $service) {}

    public function dashboard(): View
    {
        return view('enterprise-deployment.dashboard', [
            'stats' => $this->service->dashboardStats(),
        ]);
    }

    public function packageExplorer(): View
    {
        return view('enterprise-deployment.package-explorer', [
            'manifests' => DeploymentPackageManifest::latest()->paginate(50),
        ]);
    }

    public function rolloutValidation(): View
    {
        return view('enterprise-deployment.rollout-validation', [
            'runs' => RolloutValidationRun::latest()->paginate(50),
        ]);
    }

    public function upgradeGovernance(): View
    {
        return view('enterprise-deployment.upgrade-governance', [
            'upgrades' => DeploymentUpgradeHistory::latest()->paginate(50),
        ]);
    }

    public function driftDashboard(): View
    {
        return view('enterprise-deployment.drift-dashboard', [
            'reports' => DeploymentDriftReport::latest()->paginate(50),
        ]);
    }

    public function environmentValidation(): View
    {
        return view('enterprise-deployment.environment-validation', [
            'reports' => EnvironmentValidationReport::latest()->paginate(50),
        ]);
    }

    public function checkpointExplorer(): View
    {
        return view('enterprise-deployment.checkpoint-explorer', [
            'checkpoints' => RolloutCheckpointHistory::latest()->paginate(50),
        ]);
    }

    public function observabilityViewer(): View
    {
        return view('enterprise-deployment.observability-viewer', [
            'snapshots' => DeploymentObservabilitySnapshot::latest()->paginate(50),
        ]);
    }

    public function auditTimeline(): View
    {
        return view('enterprise-deployment.audit-timeline', [
            'events' => EnterpriseDeploymentAudit::latest()->paginate(50),
        ]);
    }
}
