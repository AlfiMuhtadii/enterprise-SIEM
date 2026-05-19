<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EndpointAgent;
use App\Services\EndpointStreamingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EndpointStreamApiController extends Controller
{
    public function __construct(private readonly EndpointStreamingService $svc) {}

    /**
     * Ingest a stream batch from an endpoint agent.
     * Idempotent — duplicate event_ids are silently skipped.
     */
    public function ingestBatch(Request $request, string $agentId): JsonResponse
    {
        $agent = EndpointAgent::where('agent_id', $agentId)->first();
        if (!$agent) {
            return response()->json(['error' => 'Agent not found'], 404);
        }

        $payload  = $request->json()->all();
        $events   = $payload['events'] ?? [];
        $batchId  = $payload['batch_id'] ?? '';

        if (!is_array($events) || count($events) === 0) {
            return response()->json(['error' => 'events array required'], 422);
        }

        $result = $this->svc->ingestBatch($agentId, $agent->hostname ?? $agentId, $events, $batchId);
        return response()->json($result, 202);
    }

    public function getStreamEvents(Request $request): JsonResponse
    {
        $agentId   = $request->query('agent_id');
        $eventType = $request->query('event_type');
        $events    = $this->svc->getRecentEvents(50, $agentId ?: null, $eventType ?: null);
        return response()->json(['events' => $events]);
    }

    public function getStreamHealth(): JsonResponse
    {
        $health = $this->svc->getStreamHealthSummary();
        return response()->json([
            'health'        => $health,
            'advisory_note' => 'Near-real-time advisory telemetry. Not kernel-level EDR.',
        ]);
    }

    public function getStreamCheckpoints(): JsonResponse
    {
        return response()->json(['checkpoints' => $this->svc->getLatestCheckpoints(20)]);
    }

    public function getReplayWindow(Request $request, string $agentId): JsonResponse
    {
        $fromSequence = (int) $request->query('from_sequence', 0);
        $events = $this->svc->getReplayWindow($agentId, $fromSequence);
        $gaps   = $this->svc->detectSequenceGaps($agentId);
        return response()->json([
            'events'        => $events,
            'gaps'          => $gaps,
            'bounded_window'=> EndpointStreamingService::REPLAY_WINDOW_SECONDS,
        ]);
    }

    public function getRapidAnalytics(Request $request, string $agentId): JsonResponse
    {
        return response()->json([
            'rapid_shell_chains'             => $this->svc->detectRapidShellChains($agentId),
            'burst_outbound'                 => $this->svc->detectBurstOutbound($agentId),
            'short_lived_suspicious'         => $this->svc->detectShortLivedSuspiciousProcesses($agentId),
            'rapid_persistence'              => $this->svc->detectRapidPersistenceCreation($agentId),
            'stream_beacon_pattern'          => $this->svc->detectStreamBeaconPattern($agentId),
            'advisory_only'                  => true,
            'no_autonomous_containment'      => true,
        ]);
    }
}
