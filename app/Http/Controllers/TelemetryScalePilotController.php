<?php

namespace App\Http\Controllers;

use App\Models\TelemetryScaleValidationRun;
use App\Models\ReplayScaleRecoveryRun;
use App\Models\QueueRecoveryValidationReport;
use App\Models\AnalystLoadStabilityReport;
use App\Models\InfrastructurePressureRun;
use App\Models\TelemetryGrowthDriftReport;
use App\Models\ScaleObservationWindow;
use App\Models\ScalePilotAudit;
use App\Services\TelemetryScalePilotService;
use Illuminate\View\View;

class TelemetryScalePilotController extends Controller
{
    public function __construct(private TelemetryScalePilotService $service) {}

    public function dashboard(): View
    {
        return view('telemetry-scale-pilot.dashboard', [
            'stats' => $this->service->dashboardStats(),
        ]);
    }

    public function replayRecovery(): View
    {
        return view('telemetry-scale-pilot.replay-recovery', [
            'recoveries' => ReplayScaleRecoveryRun::latest()->paginate(50),
        ]);
    }

    public function queuePressure(): View
    {
        return view('telemetry-scale-pilot.queue-pressure', [
            'reports' => QueueRecoveryValidationReport::latest()->paginate(50),
        ]);
    }

    public function analystLoad(): View
    {
        return view('telemetry-scale-pilot.analyst-load', [
            'reports' => AnalystLoadStabilityReport::latest()->paginate(50),
        ]);
    }

    public function infrastructure(): View
    {
        return view('telemetry-scale-pilot.infrastructure', [
            'runs' => InfrastructurePressureRun::latest()->paginate(50),
        ]);
    }

    public function drift(): View
    {
        return view('telemetry-scale-pilot.drift', [
            'reports' => TelemetryGrowthDriftReport::latest()->paginate(50),
        ]);
    }

    public function observation(): View
    {
        return view('telemetry-scale-pilot.observation', [
            'windows' => ScaleObservationWindow::latest()->paginate(50),
        ]);
    }

    public function continuity(): View
    {
        return view('telemetry-scale-pilot.continuity', [
            'runs' => TelemetryScaleValidationRun::latest()->paginate(50),
        ]);
    }

    public function audit(): View
    {
        return view('telemetry-scale-pilot.audit', [
            'events' => ScalePilotAudit::latest()->paginate(50),
        ]);
    }
}
