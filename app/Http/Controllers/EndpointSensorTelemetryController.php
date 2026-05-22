<?php

namespace App\Http\Controllers;

use App\Models\EndpointFileHashLineage;
use App\Models\EndpointModuleLoad;
use App\Models\EndpointRegistryTimeline;
use App\Models\EndpointSocketLifecycle;
use App\Models\EndpointProcessAncestryValidation;
use App\Models\EndpointAntiEvasionIndicator;
use App\Models\EndpointRuntimeVisibility;
use App\Models\EndpointLineageConfidence;
use App\Models\EndpointSocketAnomaly;
use App\Services\EndpointSensorAdvancedTelemetryService;
use Illuminate\View\View;

class EndpointSensorTelemetryController extends Controller
{
    public function __construct(private EndpointSensorAdvancedTelemetryService $service) {}

    public function dashboard(): View
    {
        return view('endpoint-sensor-telemetry.dashboard', [
            'stats' => $this->service->dashboardStats(),
        ]);
    }

    public function fileHashLineage(): View
    {
        return view('endpoint-sensor-telemetry.file-hash-lineage', [
            'records' => EndpointFileHashLineage::latest()->paginate(50),
        ]);
    }

    public function moduleDll(): View
    {
        return view('endpoint-sensor-telemetry.module-dll', [
            'loads' => EndpointModuleLoad::latest()->paginate(50),
        ]);
    }

    public function registryTimeline(): View
    {
        return view('endpoint-sensor-telemetry.registry-timeline', [
            'timelines' => EndpointRegistryTimeline::latest()->paginate(50),
        ]);
    }

    public function socketLifecycle(): View
    {
        return view('endpoint-sensor-telemetry.socket-lifecycle', [
            'sockets' => EndpointSocketLifecycle::latest()->paginate(50),
        ]);
    }

    public function processAncestry(): View
    {
        return view('endpoint-sensor-telemetry.process-ancestry', [
            'validations' => EndpointProcessAncestryValidation::latest()->paginate(50),
        ]);
    }

    public function antiEvasion(): View
    {
        return view('endpoint-sensor-telemetry.anti-evasion', [
            'indicators' => EndpointAntiEvasionIndicator::latest()->paginate(50),
        ]);
    }

    public function runtimeVisibility(): View
    {
        return view('endpoint-sensor-telemetry.runtime-visibility', [
            'snapshots' => EndpointRuntimeVisibility::latest()->paginate(50),
        ]);
    }

    public function lineageConfidence(): View
    {
        return view('endpoint-sensor-telemetry.lineage-confidence', [
            'records' => EndpointLineageConfidence::latest()->paginate(50),
        ]);
    }
}
