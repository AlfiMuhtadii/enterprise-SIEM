<?php

namespace App\Http\Controllers\Endpoint;

use App\Http\Controllers\Controller;
use App\Models\CollectorHealthEvent;
use App\Models\CollectorRestartAudit;
use App\Models\EndpointUpgradeValidation;
use App\Models\OfflineRecoveryRun;
use App\Models\PackageSignatureValidation;
use App\Models\SensorResourceSnapshot;
use App\Models\TelemetryGapReport;
use App\Models\TelemetryIntegrityRun;
use App\Models\TelemetrySequenceValidation;
use App\Services\SensorHardeningService;

class SensorHardeningController extends Controller
{
    public function __construct(private SensorHardeningService $svc) {}

    public function sensorHealthDashboard()
    {
        $stats = $this->svc->getDashboardStats();
        return view('sensor-hardening.sensor-health-dashboard', compact('stats'));
    }

    public function collectorLifecycleExplorer()
    {
        $events = CollectorHealthEvent::latest()->limit(100)->get();
        $states = CollectorHealthEvent::HEALTH_STATES;
        return view('sensor-hardening.collector-lifecycle-explorer', compact('events', 'states'));
    }

    public function telemetryIntegrityViewer()
    {
        $runs = TelemetryIntegrityRun::latest()->limit(50)->get();
        return view('sensor-hardening.telemetry-integrity-viewer', compact('runs'));
    }

    public function offlineRecoveryConsole()
    {
        $runs = OfflineRecoveryRun::latest()->limit(50)->get();
        return view('sensor-hardening.offline-recovery-console', compact('runs'));
    }

    public function packageSignatureValidationViewer()
    {
        $validations = PackageSignatureValidation::latest()->limit(50)->get();
        return view('sensor-hardening.package-signature-validation-viewer', compact('validations'));
    }

    public function telemetryGapTimeline()
    {
        $reports = TelemetryGapReport::latest()->limit(100)->get();
        return view('sensor-hardening.telemetry-gap-timeline', compact('reports'));
    }

    public function collectorRestartAudit()
    {
        $audits = CollectorRestartAudit::latest()->limit(100)->get();
        return view('sensor-hardening.collector-restart-audit', compact('audits'));
    }

    public function endpointUpgradeValidationExplorer()
    {
        $validations = EndpointUpgradeValidation::latest()->limit(50)->get();
        return view('sensor-hardening.endpoint-upgrade-validation-explorer', compact('validations'));
    }

    public function sensorResourceGovernanceDashboard()
    {
        $snapshots   = SensorResourceSnapshot::latest()->limit(100)->get();
        $byState     = $snapshots->groupBy('pressure_state');
        $sequenceRuns= TelemetrySequenceValidation::latest()->limit(50)->get();
        return view('sensor-hardening.sensor-resource-governance-dashboard', compact('snapshots', 'byState', 'sequenceRuns'));
    }
}
