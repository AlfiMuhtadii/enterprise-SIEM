<?php

namespace App\Http\Controllers\Endpoint;

use App\Http\Controllers\Controller;
use App\Models\EndpointAgent;
use App\Services\BehavioralAnalyticsService;
use Illuminate\View\View;

/**
 * Behavioral Detection Analytics UI.
 * Advisory-only, shadow-mode. No active containment.
 */
class EndpointAnalyticsController extends Controller
{
    public function __construct(private BehavioralAnalyticsService $analyticsService) {}

    public function findingsDashboard(string $agentId): View
    {
        $agent    = EndpointAgent::where('agent_id', $agentId)->firstOrFail();
        $findings = $this->analyticsService->getRecentFindings($agent);
        return view('endpoint.analytics-dashboard', compact('agent', 'findings'));
    }

    public function executionChainTimeline(string $agentId): View
    {
        $agent  = EndpointAgent::where('agent_id', $agentId)->firstOrFail();
        $chains = $this->analyticsService->getExecutionChains($agent);
        return view('endpoint.execution-chain-timeline', compact('agent', 'chains'));
    }

    public function beaconPatternView(string $agentId): View
    {
        $agent    = EndpointAgent::where('agent_id', $agentId)->firstOrFail();
        $patterns = $this->analyticsService->getBeaconPatterns($agent);
        return view('endpoint.beacon-pattern', compact('agent', 'patterns'));
    }

    public function rareParentChildView(string $agentId): View
    {
        $agent    = EndpointAgent::where('agent_id', $agentId)->firstOrFail();
        $findings = $this->analyticsService->getRareParentChildFindings($agent);
        return view('endpoint.rare-parent-child', compact('agent', 'findings'));
    }

    public function persistenceCorrelationView(string $agentId): View
    {
        $agent    = EndpointAgent::where('agent_id', $agentId)->firstOrFail();
        $findings = $this->analyticsService->getPersistenceCorrelationFindings($agent);
        return view('endpoint.persistence-correlation', compact('agent', 'findings'));
    }
}
