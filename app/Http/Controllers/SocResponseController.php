<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocResponseController extends Controller
{
    private const SAFE_ACTIONS = ['collect-now', 'refresh-policy', 'flush-local-queue', 'rotate-agent-secret', 'restart-agent-loop'];
    private const CONTAINMENT_SIMULATION_ACTIONS = ['isolate-host', 'block-ioc', 'policy-quarantine'];

    public function recommend(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source_type' => ['required', 'in:alert,incident'],
            'source_id' => ['required', 'string', 'max:96'],
            'agent_id' => ['nullable', 'string', 'max:96'],
            'action_type' => ['required', 'in:collect-now,refresh-policy,flush-local-queue,rotate-agent-secret,restart-agent-loop,isolate-host,block-ioc,policy-quarantine'],
            'severity' => ['nullable', 'in:low,medium,high,critical'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $row = [
            'response_id' => 'resp-'.Str::uuid()->toString(),
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'],
            'agent_id' => $data['agent_id'] ?? null,
            'action_type' => $data['action_type'],
            'status' => 'pending_approval',
            'severity' => $data['severity'] ?? 'medium',
            'recommended_by' => $request->user()->email,
            'suggestion' => json_encode(['reason' => $data['reason'] ?? 'analyst recommended response']),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('soc_response_workflows')->insert($row);
        AuditLogger::log($request->user()->email, 'response.recommend', 'response', $row['response_id'], null, $row);

        return back()->with('status', 'Response recommendation created.');
    }

    public function decide(Request $request, string $responseId): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $before = DB::table('soc_response_workflows')->where('response_id', $responseId)->first();
        abort_if(!$before, 404);

        if ($data['decision'] === 'approve' && $before->recommended_by === $request->user()->email) {
            return back()->withErrors(['decision' => 'Self-approval is blocked: you cannot approve your own response recommendation.']);
        }

        if ($data['decision'] === 'reject') {
            DB::table('soc_response_workflows')->where('response_id', $responseId)->update([
                'status' => 'rejected',
                'approved_by' => $request->user()->email,
                'approved_at' => now(),
                'rejection_reason' => $data['rejection_reason'] ?? null,
                'updated_at' => now(),
            ]);
            AuditLogger::log($request->user()->email, 'response.reject', 'response', $responseId, $before, null);
            return back()->with('status', 'Response rejected.');
        }

        if (in_array($before->action_type, self::CONTAINMENT_SIMULATION_ACTIONS, true)) {
            $result = $this->simulateContainment($before, $request->user()->email);
            DB::table('soc_response_workflows')->where('response_id', $responseId)->update([
                'status' => 'approved_simulated',
                'approved_by' => $request->user()->email,
                'approved_at' => now(),
                'executed_at' => now(),
                'completed_at' => now(),
                'execution_result' => json_encode($result),
                'updated_at' => now(),
            ]);
            AuditLogger::log($request->user()->email, 'response.approve_containment_simulation', 'response', $responseId, $before, $result);

            return back()->with('status', 'Containment simulation approved and recorded.');
        }

        if (!$before->agent_id || !in_array($before->action_type, self::SAFE_ACTIONS, true)) {
            DB::table('soc_response_workflows')->where('response_id', $responseId)->update([
                'status' => 'failed',
                'execution_result' => json_encode(['error' => 'missing agent or unsupported action']),
                'updated_at' => now(),
            ]);
            return back()->with('status', 'Response failed validation.');
        }

        $commandId = 'cmd-'.Str::uuid()->toString();
        DB::table('agent_commands')->insert([
            'command_id' => $commandId,
            'agent_id' => $before->agent_id,
            'command_type' => $before->action_type,
            'status' => 'queued',
            'payload' => json_encode([]),
            'queued_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'created_by' => $request->user()->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('soc_response_workflows')->where('response_id', $responseId)->update([
            'status' => 'approved_queued',
            'approved_by' => $request->user()->email,
            'approved_at' => now(),
            'executed_at' => now(),
            'execution_result' => json_encode(['agent_command_id' => $commandId]),
            'updated_at' => now(),
        ]);
        AuditLogger::log($request->user()->email, 'response.approve_queue_command', 'response', $responseId, $before, ['command_id' => $commandId]);

        return back()->with('status', 'Response approved and command queued.');
    }

    private function simulateContainment(object $response, string $actor): array
    {
        $targetType = match ($response->action_type) {
            'isolate-host', 'policy-quarantine' => 'host',
            'block-ioc' => 'ioc',
            default => 'unknown',
        };
        $targetValue = $response->agent_id ?: $response->source_id;
        $simulationId = 'sim-'.Str::uuid()->toString();
        $result = match ($response->action_type) {
            'isolate-host' => [
                'simulated' => true,
                'control' => 'network_containment',
                'network_policy' => 'deny_non_soc_traffic',
                'allowed_channels' => ['soc_ingestion', 'agent_command_polling'],
                'reversible' => true,
                'note' => 'No network rule was applied. This records the approval path and expected containment effect.',
            ],
            'block-ioc' => [
                'simulated' => true,
                'control' => 'ioc_blocklist',
                'ioc' => $response->source_id,
                'block_targets' => ['proxy', 'dns_filter', 'edr_policy'],
                'reversible' => true,
                'note' => 'No external firewall, DNS, or EDR integration was changed.',
            ],
            'policy-quarantine' => [
                'simulated' => true,
                'control' => 'endpoint_policy_mode',
                'policy_mode' => 'quarantine',
                'collection_profile' => 'high_fidelity_process_network_file',
                'command_execution' => false,
                'reversible' => true,
                'note' => 'Agent policy was not changed. This models the quarantine decision safely.',
            ],
            default => ['simulated' => true],
        };

        DB::table('containment_simulations')->insert([
            'simulation_id' => $simulationId,
            'response_id' => $response->response_id,
            'target_type' => $targetType,
            'target_value' => $targetValue,
            'action_type' => $response->action_type,
            'status' => 'approved_simulated',
            'requested_by' => $response->recommended_by,
            'approved_by' => $actor,
            'approved_at' => now(),
            'simulation_result' => json_encode($result),
            'metadata' => json_encode([
                'source_type' => $response->source_type,
                'source_id' => $response->source_id,
                'agent_id' => $response->agent_id,
                'severity' => $response->severity,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['simulation_id' => $simulationId] + $result;
    }
}
