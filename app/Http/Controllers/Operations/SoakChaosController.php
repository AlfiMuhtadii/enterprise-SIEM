<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\SoakValidationRun;
use App\Models\SoakValidationMetric;
use App\Models\ChaosSimulationRun;
use App\Models\ChaosFailureEvent;
use App\Models\RecoveryValidationArtifact;
use App\Models\OperationalDriftReport;
use App\Models\ReplayRecoveryRun;
use App\Models\TelemetryContinuityReport;
use App\Models\BoundedFailureScenario;
use App\Services\SoakChaosValidationService;

class SoakChaosController extends Controller
{
    public function __construct(private SoakChaosValidationService $service) {}

    public function soakDashboard()
    {
        $stats    = $this->service->dashboardStats();
        $recent   = SoakValidationRun::latest()->limit(20)->get();
        return view('soak-chaos.soak-dashboard', compact('stats', 'recent'));
    }

    public function chaosExplorer()
    {
        $runs     = ChaosSimulationRun::latest()->limit(50)->get();
        $byVerdict= ChaosSimulationRun::selectRaw('verdict, count(*) as cnt')->groupBy('verdict')->pluck('cnt', 'verdict');
        return view('soak-chaos.chaos-explorer', compact('runs', 'byVerdict'));
    }

    public function replayRecovery()
    {
        $runs = ReplayRecoveryRun::latest()->limit(50)->get();
        return view('soak-chaos.replay-recovery', compact('runs'));
    }

    public function telemetryContinuity()
    {
        $reports = TelemetryContinuityReport::latest()->limit(50)->get();
        return view('soak-chaos.telemetry-continuity', compact('reports'));
    }

    public function driftDetection()
    {
        $reports   = OperationalDriftReport::latest()->limit(50)->get();
        $byType    = OperationalDriftReport::selectRaw('drift_type, count(*) as cnt')->groupBy('drift_type')->pluck('cnt', 'drift_type');
        $exceeded  = OperationalDriftReport::where('drift_exceeds_threshold', true)->count();
        return view('soak-chaos.drift-detection', compact('reports', 'byType', 'exceeded'));
    }

    public function queuePressure()
    {
        $metrics = SoakValidationMetric::where('metric_name', 'like', 'queue%')->latest()->limit(50)->get();
        return view('soak-chaos.queue-pressure', compact('metrics'));
    }

    public function workerRestart()
    {
        $events = ChaosFailureEvent::where('component', 'worker')->latest()->limit(50)->get();
        $stats  = $this->service->dashboardStats();
        return view('soak-chaos.worker-restart', compact('events', 'stats'));
    }

    public function recoveryTimeline()
    {
        $artifacts = RecoveryValidationArtifact::latest()->limit(50)->get();
        $stats     = [
            'pass'    => RecoveryValidationArtifact::where('verdict', 'pass')->count(),
            'fail'    => RecoveryValidationArtifact::where('verdict', 'fail')->count(),
            'partial' => RecoveryValidationArtifact::where('verdict', 'partial')->count(),
        ];
        return view('soak-chaos.recovery-timeline', compact('artifacts', 'stats'));
    }

    public function stabilityDashboard()
    {
        $stats    = $this->service->dashboardStats();
        $scenarios= BoundedFailureScenario::all();
        $drifts   = OperationalDriftReport::where('drift_exceeds_threshold', true)->latest()->limit(10)->get();
        return view('soak-chaos.stability-dashboard', compact('stats', 'scenarios', 'drifts'));
    }
}
