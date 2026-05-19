<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EndpointAgent;
use App\Models\EndpointResponseCommand;
use App\Services\EndpointAgentService;
use App\Services\EndpointFleetService;
use App\Services\EndpointResponseCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Agent-facing API — no session/CSRF, authenticated via enrollment token or
 * X-Agent-Signature on per-agent endpoints.
 */
class EndpointAgentApiController extends Controller
{
    public function __construct(
        private EndpointAgentService $agentService,
        private EndpointResponseCommandService $commandService,
        private EndpointFleetService $fleetService,
    ) {}

    // -----------------------------------------------------------------------
    // POST /api/agents/enroll
    // -----------------------------------------------------------------------

    public function enroll(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?: $request->input('enrollment_token', '');

        if (!$this->agentService->validateEnrollmentToken($token)) {
            return response()->json(['error' => 'invalid_enrollment_token'], 401);
        }

        $hostId      = $request->input('host_id', '');
        $hostname    = $request->input('hostname', $hostId);
        $agentVersion= $request->input('agent_version', '1.0.0');
        $platform    = $request->input('platform');
        $ipAddress   = $request->ip();

        if (empty($hostId)) {
            return response()->json(['error' => 'host_id_required'], 422);
        }

        $agent = $this->agentService->enroll(
            $token, $hostId, $hostname, $agentVersion, null, $ipAddress, $platform
        );

        return response()->json([
            'agent_id'       => $agent->agent_id,
            'host_id'        => $agent->host_id,
            'hostname'       => $agent->hostname,
            'health_state'   => $agent->health_state,
            'enrolled_at'    => $agent->enrolled_at?->toIso8601String(),
        ], 201);
    }

    // -----------------------------------------------------------------------
    // POST /api/agents/{agentId}/heartbeat
    // -----------------------------------------------------------------------

    public function heartbeat(Request $request, string $agentId): JsonResponse
    {
        $agent = EndpointAgent::where('agent_id', $agentId)->first();
        if (!$agent) {
            return response()->json(['error' => 'agent_not_found'], 404);
        }

        $signature  = $request->header('X-Agent-Signature', '');
        $rawPayload = $request->getContent();
        $payload    = json_decode($rawPayload, true) ?? [];

        $result = $this->agentService->processHeartbeat($agent, $signature, $rawPayload, $payload);

        // Record spool snapshot if agent reported spool_stats (Fleet Hardening Phase 1)
        if ($result['valid'] && !empty($payload['spool_stats'])) {
            $this->fleetService->recordSpoolSnapshot(
                $agent,
                $payload['spool_stats'],
                $payload['trace_id'] ?? null
            );
        }

        $status = $result['valid'] ? 200 : 422;
        return response()->json([
            'ok'           => $result['valid'],
            'health_state' => $result['health_state'],
            'agent_id'     => $agent->agent_id,
        ], $status);
    }

    // -----------------------------------------------------------------------
    // GET /api/agents/{agentId}/config
    // -----------------------------------------------------------------------

    public function agentConfig(Request $request, string $agentId): JsonResponse
    {
        $agent = EndpointAgent::where('agent_id', $agentId)->first();
        if (!$agent) {
            return response()->json(['error' => 'agent_not_found'], 404);
        }

        $config = $this->agentService->getConfig($agent);

        return response()->json([
            'agent_id' => $agent->agent_id,
            'config'   => $config,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /api/agents/{agentId}/commands
    // Returns dispatched commands pending acknowledgement.
    // -----------------------------------------------------------------------

    public function pollCommands(Request $request, string $agentId): JsonResponse
    {
        $agent = EndpointAgent::where('agent_id', $agentId)->first();
        if (!$agent) {
            return response()->json(['error' => 'agent_not_found'], 404);
        }

        $commands = $this->commandService->getPendingCommands($agent);

        return response()->json([
            'agent_id' => $agentId,
            'commands' => $commands->map(fn ($c) => [
                'command_id'   => $c->command_id,
                'command_type' => $c->command_type,
                'parameters'   => $c->parameters ?? [],
                'trace_id'     => $c->trace_id,
                'dispatched_at'=> $c->dispatched_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /api/agents/{agentId}/commands/{commandId}/ack
    // Agent acknowledges receipt of a command.
    // -----------------------------------------------------------------------

    public function acknowledgeCommand(Request $request, string $agentId, string $commandId): JsonResponse
    {
        $agent = EndpointAgent::where('agent_id', $agentId)->first();
        if (!$agent) {
            return response()->json(['error' => 'agent_not_found'], 404);
        }

        $command = EndpointResponseCommand::where('command_id', $commandId)
            ->where('agent_id', $agent->id)
            ->first();
        if (!$command) {
            return response()->json(['error' => 'command_not_found'], 404);
        }

        $signature  = $request->header('X-Agent-Signature', '');
        $rawPayload = $request->getContent();

        $command = $this->commandService->acknowledge($command, $agentId, $signature, $rawPayload);

        return response()->json([
            'ok'         => true,
            'command_id' => $command->command_id,
            'status'     => $command->status,
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /api/agents/{agentId}/commands/{commandId}/result
    // Agent submits command execution result.
    // -----------------------------------------------------------------------

    public function commandResult(Request $request, string $agentId, string $commandId): JsonResponse
    {
        $agent = EndpointAgent::where('agent_id', $agentId)->first();
        if (!$agent) {
            return response()->json(['error' => 'agent_not_found'], 404);
        }

        $command = EndpointResponseCommand::where('command_id', $commandId)
            ->where('agent_id', $agent->id)
            ->first();
        if (!$command) {
            return response()->json(['error' => 'command_not_found'], 404);
        }

        $signature  = $request->header('X-Agent-Signature', '');
        $rawPayload = $request->getContent();
        $payload    = json_decode($rawPayload, true) ?? [];

        $command = $this->commandService->receiveResult(
            $command, $agentId, $signature, $rawPayload, $payload
        );

        return response()->json([
            'ok'         => true,
            'command_id' => $command->command_id,
            'status'     => $command->status,
        ]);
    }
}
