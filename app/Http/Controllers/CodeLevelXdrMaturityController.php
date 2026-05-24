<?php

namespace App\Http\Controllers;

use App\Models\SyntheticAttackFixture;
use App\Models\DetectionQualityScorecard;
use App\Models\FalsePositiveNegativeReport;
use App\Models\TelemetryQualityScorecard;
use App\Models\AnalystTriageSimulationRun;
use App\Models\PerformanceHotspotReport;
use App\Models\NoisyEnterpriseSimulation;
use App\Models\XdrMaturityScorecard;
use App\Models\XdrReadinessReportArtifact;
use App\Services\CodeLevelXdrMaturityService;

class CodeLevelXdrMaturityController extends Controller
{
    public function __construct(private CodeLevelXdrMaturityService $svc) {}

    public function dashboard()
    {
        return view('xdr-maturity.dashboard', [
            'stats' => $this->svc->dashboardStats(),
        ]);
    }

    public function fixtureExplorer()
    {
        return view('xdr-maturity.fixture-explorer', [
            'fixtures' => SyntheticAttackFixture::latest()->paginate(20),
        ]);
    }

    public function detectionScorecard()
    {
        return view('xdr-maturity.detection-scorecard', [
            'scorecards' => DetectionQualityScorecard::latest('id')->paginate(20),
        ]);
    }

    public function fpfnAnalysis()
    {
        return view('xdr-maturity.fpfn-analysis', [
            'reports' => FalsePositiveNegativeReport::latest('id')->paginate(20),
        ]);
    }

    public function telemetryQuality()
    {
        return view('xdr-maturity.telemetry-quality', [
            'scorecards' => TelemetryQualityScorecard::latest('id')->paginate(20),
        ]);
    }

    public function triageSimulation()
    {
        return view('xdr-maturity.triage-simulation', [
            'runs' => AnalystTriageSimulationRun::latest('id')->paginate(20),
        ]);
    }

    public function hotspotExplorer()
    {
        return view('xdr-maturity.hotspot-explorer', [
            'reports' => PerformanceHotspotReport::latest('id')->paginate(20),
        ]);
    }

    public function noisySimulation()
    {
        return view('xdr-maturity.noisy-simulation', [
            'simulations' => NoisyEnterpriseSimulation::latest('id')->paginate(20),
        ]);
    }

    public function readinessReport()
    {
        return view('xdr-maturity.readiness-report', [
            'artifacts'  => XdrReadinessReportArtifact::latest('id')->paginate(10),
            'scorecards' => XdrMaturityScorecard::latest('id')->paginate(10),
        ]);
    }
}
