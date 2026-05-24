<?php

namespace App\Http\Controllers;

use App\Models\DemoScenarioRun;
use App\Models\DemoReadinessSnapshot;
use App\Models\PlatformShowcaseExport;
use App\Services\DemoPlatformPackagingService;

class DemoPlatformPackagingController extends Controller
{
    public function __construct(private DemoPlatformPackagingService $svc) {}

    public function dashboard()
    {
        return view('demo-platform.dashboard', [
            'stats' => $this->svc->dashboardStats(),
        ]);
    }

    public function scenarioLauncher()
    {
        return view('demo-platform.scenario-launcher', [
            'scenarios' => DemoScenarioRun::DEMO_SCENARIOS,
            'runs'      => DemoScenarioRun::latest('id')->paginate(20),
        ]);
    }

    public function attackTimeline()
    {
        return view('demo-platform.attack-timeline', [
            'runs' => DemoScenarioRun::where('scenario_state', 'completed')->latest('id')->paginate(20),
        ]);
    }

    public function readinessDashboard()
    {
        return view('demo-platform.readiness-dashboard', [
            'snapshots' => DemoReadinessSnapshot::latest('id')->paginate(10),
            'env'       => $this->svc->validateDemoEnvironment(),
        ]);
    }

    public function architectureExplorer()
    {
        $export = $this->svc->exportPlatformShowcase('architecture_summary', 'json');
        return view('demo-platform.architecture-explorer', [
            'summary' => $export->export_content,
        ]);
    }

    public function capabilityMatrix()
    {
        return view('demo-platform.capability-matrix', [
            'matrix' => $this->svc->generateCapabilityMatrix(),
        ]);
    }

    public function replayExplorer()
    {
        return view('demo-platform.replay-explorer', [
            'runs' => DemoScenarioRun::where('is_deterministic', true)->latest('id')->paginate(20),
        ]);
    }

    public function walkthroughConsole()
    {
        return view('demo-platform.walkthrough-console', [
            'scenarios' => DemoScenarioRun::DEMO_SCENARIOS,
        ]);
    }

    public function showcaseDashboard()
    {
        return view('demo-platform.showcase-dashboard', [
            'stats'    => $this->svc->dashboardStats(),
            'matrix'   => $this->svc->generateCapabilityMatrix(),
            'exports'  => PlatformShowcaseExport::latest('id')->paginate(5),
        ]);
    }
}
