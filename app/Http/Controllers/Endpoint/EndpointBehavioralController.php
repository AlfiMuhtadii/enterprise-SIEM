<?php

namespace App\Http\Controllers\Endpoint;

use App\Http\Controllers\Controller;
use App\Models\EndpointAgent;
use App\Services\EndpointBehavioralService;
use Illuminate\View\View;

/**
 * Behavioral endpoint visibility UI.
 * Shadow-only — no active containment, no enforcement.
 */
class EndpointBehavioralController extends Controller
{
    public function __construct(private EndpointBehavioralService $behavioralService) {}

    public function activityTimeline(string $agentId): View
    {
        $agent    = EndpointAgent::where('agent_id', $agentId)->firstOrFail();
        $timeline = $this->behavioralService->getActivityTimeline($agent);
        return view('endpoint.activity-timeline', compact('agent', 'timeline'));
    }

    public function processTree(string $agentId): View
    {
        $agent     = EndpointAgent::where('agent_id', $agentId)->firstOrFail();
        $processes = $this->behavioralService->getProcessTree($agent);
        return view('endpoint.process-tree', compact('agent', 'processes'));
    }

    public function persistenceInventory(string $agentId): View
    {
        $agent = EndpointAgent::where('agent_id', $agentId)->firstOrFail();
        $items = $this->behavioralService->getPersistenceInventory($agent);
        return view('endpoint.persistence-inventory', compact('agent', 'items'));
    }

    public function processNetwork(string $agentId): View
    {
        $agent        = EndpointAgent::where('agent_id', $agentId)->firstOrFail();
        $correlations = $this->behavioralService->getNetworkCorrelations($agent);
        return view('endpoint.process-network', compact('agent', 'correlations'));
    }

    public function longLivedProcesses(string $agentId): View
    {
        $agent     = EndpointAgent::where('agent_id', $agentId)->firstOrFail();
        $processes = $this->behavioralService->getLongLivedProcesses($agent);
        return view('endpoint.long-lived-processes', compact('agent', 'processes'));
    }
}
