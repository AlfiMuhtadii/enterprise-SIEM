<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EndpointAgent;
use App\Models\EndpointTamperEvent;
use App\Services\EndpointFleetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint Fleet Hardening Phase 1 — JSON API controller.
 * All responses include advisory_only=true.
 * No autonomous enforcement endpoints.
 */
class EndpointFleetApiController extends Controller
{
    public function __construct(private readonly EndpointFleetService $fleetService) {}

    public function getDashboardStats(): JsonResponse
    {
        return response()->json([
            'ok'           => true,
            'advisory_only'=> true,
            'stats'        => $this->fleetService->getDashboardStats(),
        ]);
    }

    public function getStaleAgents(Request $request): JsonResponse
    {
        $interval = (int) $request->input('heartbeat_interval', 60);

        return response()->json([
            'ok'           => true,
            'advisory_only'=> true,
            'stale_agents' => $this->fleetService->getStaleAgents($interval)->values(),
        ]);
    }

    public function getTelemetryLag(): JsonResponse
    {
        return response()->json([
            'ok'           => true,
            'advisory_only'=> true,
            'lag_summary'  => $this->fleetService->getTelemetryLagSummary(50)->values(),
        ]);
    }

    public function getTamperSummary(Request $request): JsonResponse
    {
        $days = min((int) $request->input('days', 7), 30);

        return response()->json([
            'ok'           => true,
            'advisory_only'=> true,
            'autonomous_action' => false,
            'tamper_summary' => $this->fleetService->getTamperVisibilitySummary($days)->values(),
        ]);
    }

    public function detectTamper(Request $request): JsonResponse
    {
        $request->validate([
            'agent_id' => 'required|string|max:255',
        ]);

        $agent = EndpointAgent::where('agent_id', $request->input('agent_id'))->first();
        if (!$agent) {
            return response()->json(['ok' => false, 'error' => 'agent_not_found'], 404);
        }

        $findings = $this->fleetService->detectTamperEvents($agent);

        return response()->json([
            'ok'                => true,
            'advisory_only'     => true,
            'autonomous_action' => false,
            'findings_count'    => $findings->count(),
            'findings'          => $findings->values(),
            'disclaimer'        => 'Endpoint operations are advisory-only. No autonomous containment or enforcement is executed.',
        ]);
    }

    public function getSpoolHealth(): JsonResponse
    {
        return response()->json([
            'ok'           => true,
            'advisory_only'=> true,
            'spool_summary'=> $this->fleetService->getSpoolHealthSummary(50)->values(),
            'warnings'     => $this->fleetService->countSpoolWarnings(),
        ]);
    }

    public function getPolicyDrift(): JsonResponse
    {
        return response()->json([
            'ok'           => true,
            'advisory_only'=> true,
            'drifted_agents'=> $this->fleetService->getAgentsWithPolicyDrift()->map(fn ($d) => [
                'agent_id'   => $d['agent']->agent_id,
                'hostname'   => $d['agent']->hostname,
                'health_state'=> $d['agent']->health_state,
                'drift'      => $d['drift'],
                'severity'   => $d['severity'],
            ])->values(),
        ]);
    }
}
