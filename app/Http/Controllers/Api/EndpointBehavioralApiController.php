<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EndpointAgent;
use App\Services\EndpointBehavioralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Agent-facing behavioral snapshot API.
 * No session/CSRF — authenticated via X-Agent-Signature.
 * Shadow-only visibility endpoint; no active containment or enforcement.
 */
class EndpointBehavioralApiController extends Controller
{
    public function __construct(private EndpointBehavioralService $behavioralService) {}

    /**
     * POST /api/agents/{agentId}/behavioral-snapshot
     * Accept and store a behavioral snapshot from the endpoint agent.
     */
    public function storeSnapshot(Request $request, string $agentId): JsonResponse
    {
        $agent = EndpointAgent::where('agent_id', $agentId)->first();
        if (!$agent) {
            return response()->json(['error' => 'agent_not_found'], 404);
        }

        $rawPayload = $request->getContent();
        $payload    = json_decode($rawPayload, true);
        if (!is_array($payload)) {
            return response()->json(['error' => 'invalid_json'], 422);
        }

        $traceId = $payload['trace_id'] ?? (string) Str::uuid();

        $snapshot = $this->behavioralService->storeSnapshot($agent, $payload, $traceId);

        return response()->json([
            'ok'           => true,
            'snapshot_id'  => $snapshot->snapshot_id,
            'agent_id'     => $agentId,
            'process_count'=> $snapshot->process_count,
            'trace_id'     => $traceId,
        ], 201);
    }
}
