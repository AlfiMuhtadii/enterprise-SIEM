<?php

namespace App\Http\Controllers;

use App\Models\OperationalValidationWindow;
use App\Models\TelemetryTrendReport;
use App\Models\AnalystBehaviorTrend;
use App\Models\FalsePositiveEvolutionReport;
use App\Models\OperationalDriftHistory;
use App\Models\GovernanceReportingRun;
use App\Models\ReplayDurabilityHistory;
use App\Models\InfrastructureStabilityReport;
use App\Models\ProductionGovernanceAudit;
use App\Services\LongRunningOperationalService;
use Illuminate\View\View;

class LongRunningOperationsController extends Controller
{
    public function __construct(private LongRunningOperationalService $service) {}

    public function dashboard(): View
    {
        return view('long-running-ops.dashboard', [
            'stats' => $this->service->dashboardStats(),
        ]);
    }

    public function telemetryTrend(): View
    {
        return view('long-running-ops.telemetry-trend', [
            'reports' => TelemetryTrendReport::latest()->paginate(50),
        ]);
    }

    public function analystBehavior(): View
    {
        return view('long-running-ops.analyst-behavior', [
            'trends' => AnalystBehaviorTrend::latest()->paginate(50),
        ]);
    }

    public function fpEvolution(): View
    {
        return view('long-running-ops.fp-evolution', [
            'reports' => FalsePositiveEvolutionReport::latest()->paginate(50),
        ]);
    }

    public function driftDashboard(): View
    {
        return view('long-running-ops.drift-dashboard', [
            'history' => OperationalDriftHistory::latest()->paginate(50),
        ]);
    }

    public function replayDurability(): View
    {
        return view('long-running-ops.replay-durability', [
            'history' => ReplayDurabilityHistory::latest()->paginate(50),
        ]);
    }

    public function infraStability(): View
    {
        return view('long-running-ops.infra-stability', [
            'reports' => InfrastructureStabilityReport::latest()->paginate(50),
        ]);
    }

    public function governanceReporting(): View
    {
        return view('long-running-ops.governance-reporting', [
            'runs' => GovernanceReportingRun::latest()->paginate(50),
        ]);
    }

    public function governanceAudit(): View
    {
        return view('long-running-ops.governance-audit', [
            'events' => ProductionGovernanceAudit::latest()->paginate(50),
        ]);
    }
}
