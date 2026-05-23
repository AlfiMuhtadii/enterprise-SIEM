<?php

namespace App\Http\Controllers;

use App\Models\ClusterTopologyReport;
use App\Models\HaValidationRun;
use App\Models\TelemetryDistributionReport;
use App\Models\FailoverCoordinationHistory;
use App\Models\DistributedReplayContinuity;
use App\Models\InfrastructureCostReport;
use App\Models\ClusterLifecycleAudit;
use App\Models\ScaleProfileValidationRun;
use App\Models\EnterpriseScaleOperationsAudit;
use App\Services\EnterpriseScaleHaService;
use Illuminate\View\View;

class EnterpriseScaleController extends Controller
{
    public function __construct(private EnterpriseScaleHaService $service) {}

    public function dashboard(): View
    {
        return view('enterprise-scale.dashboard', [
            'stats' => $this->service->dashboardStats(),
        ]);
    }

    public function clusterTopology(): View
    {
        return view('enterprise-scale.cluster-topology', [
            'reports' => ClusterTopologyReport::latest()->paginate(50),
        ]);
    }

    public function haGovernance(): View
    {
        return view('enterprise-scale.ha-governance', [
            'runs' => HaValidationRun::latest()->paginate(50),
        ]);
    }

    public function telemetryDistribution(): View
    {
        return view('enterprise-scale.telemetry-distribution', [
            'reports' => TelemetryDistributionReport::latest()->paginate(50),
        ]);
    }

    public function failoverTimeline(): View
    {
        return view('enterprise-scale.failover-timeline', [
            'history' => FailoverCoordinationHistory::latest()->paginate(50),
        ]);
    }

    public function infrastructureCost(): View
    {
        return view('enterprise-scale.infrastructure-cost', [
            'reports' => InfrastructureCostReport::latest()->paginate(50),
        ]);
    }

    public function replayContinuity(): View
    {
        return view('enterprise-scale.replay-continuity', [
            'records' => DistributedReplayContinuity::latest()->paginate(50),
        ]);
    }

    public function scaleValidation(): View
    {
        return view('enterprise-scale.scale-validation', [
            'runs' => ScaleProfileValidationRun::latest()->paginate(50),
        ]);
    }

    public function auditTimeline(): View
    {
        return view('enterprise-scale.audit-timeline', [
            'events' => EnterpriseScaleOperationsAudit::latest()->paginate(50),
        ]);
    }
}
