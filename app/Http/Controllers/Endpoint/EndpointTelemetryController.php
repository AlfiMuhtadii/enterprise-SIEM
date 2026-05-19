<?php

namespace App\Http\Controllers\Endpoint;

use App\Http\Controllers\Controller;
use App\Services\EndpointTelemetryAnalyticsService;
use Illuminate\View\View;

/**
 * Low-level endpoint telemetry UI controller.
 * Advisory-only — no process termination, no kernel enforcement, no isolation.
 */
class EndpointTelemetryController extends Controller
{
    public function __construct(
        private EndpointTelemetryAnalyticsService $telemetryService
    ) {}

    public function dashboard(): View
    {
        $stats            = $this->telemetryService->getDashboardStats();
        $processStats     = $this->telemetryService->getProcessExecutionStats();
        $networkStats     = $this->telemetryService->getNetworkConnectionStats();
        $persistenceStats = $this->telemetryService->getPersistenceIndicatorStats();
        return view('endpoint-telemetry.dashboard', compact(
            'stats', 'processStats', 'networkStats', 'persistenceStats'
        ));
    }

    public function processExplorer(): View
    {
        $stats        = $this->telemetryService->getProcessExecutionStats();
        $topInterpreters = $this->telemetryService->getTopInterpreters(7, 10);
        return view('endpoint-telemetry.process-explorer', compact('stats', 'topInterpreters'));
    }

    public function processTree(): View
    {
        $processStats = $this->telemetryService->getProcessExecutionStats();
        return view('endpoint-telemetry.process-tree', compact('processStats'));
    }

    public function networkConnections(): View
    {
        $networkStats = $this->telemetryService->getNetworkConnectionStats();
        return view('endpoint-telemetry.network-connections', compact('networkStats'));
    }

    public function scriptExecution(): View
    {
        $summary        = $this->telemetryService->getScriptExecutionSummary();
        $encodedScripts = $this->telemetryService->getEncodedScriptExecutions();
        return view('endpoint-telemetry.script-execution', compact('summary', 'encodedScripts'));
    }

    public function privilegeEscalation(): View
    {
        $summary  = $this->telemetryService->getPrivilegeEscalationSummary();
        $timeline = $this->telemetryService->getPrivilegeEscalationTimeline();
        return view('endpoint-telemetry.privilege-escalation', compact('summary', 'timeline'));
    }

    public function persistenceIndicators(): View
    {
        $persistenceStats = $this->telemetryService->getPersistenceIndicatorStats();
        return view('endpoint-telemetry.persistence-indicators', compact('persistenceStats'));
    }

    public function containerActivity(): View
    {
        $summary   = $this->telemetryService->getContainerActivitySummary();
        $breakouts = $this->telemetryService->getContainerBreakoutIndicators();
        return view('endpoint-telemetry.container-activity', compact('summary', 'breakouts'));
    }
}
