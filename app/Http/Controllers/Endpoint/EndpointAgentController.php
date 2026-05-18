<?php

namespace App\Http\Controllers\Endpoint;

use App\Http\Controllers\Controller;
use App\Models\EndpointAgent;
use App\Models\EndpointAgentConfig;
use App\Services\EndpointAgentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EndpointAgentController extends Controller
{
    public function __construct(private EndpointAgentService $agentService) {}

    /**
     * Enrollment inventory — all registered agents with health state and telemetry metrics.
     */
    public function inventory(): View
    {
        $this->agentService->refreshAllHealthStates();

        $agents = EndpointAgent::with('metrics')
            ->orderByDesc('last_seen_at')
            ->get();

        $summary = [
            'total'    => $agents->count(),
            'online'   => $agents->where('health_state', EndpointAgent::HEALTH_ONLINE)->count(),
            'degraded' => $agents->where('health_state', EndpointAgent::HEALTH_DEGRADED)->count(),
            'stale'    => $agents->where('health_state', EndpointAgent::HEALTH_STALE)->count(),
            'offline'  => $agents->where('health_state', EndpointAgent::HEALTH_OFFLINE)->count(),
        ];

        return view('endpoint.agent-inventory', compact('agents', 'summary'));
    }

    /**
     * Agent detail — enrollment data, health, heartbeat history, metrics.
     */
    public function detail(string $agentId): View
    {
        $agent = EndpointAgent::with(['metrics', 'config'])
            ->where('agent_id', $agentId)
            ->firstOrFail();

        $agent->health_state = $this->agentService->calculateHealthState($agent);

        $recentHeartbeats = $agent->heartbeats()->limit(20)->get();
        $config           = $this->agentService->getConfig($agent);

        return view('endpoint.agent-detail', compact('agent', 'recentHeartbeats', 'config'));
    }

    /**
     * Config policy view for an agent.
     */
    public function configPolicy(string $agentId): View
    {
        $agent  = EndpointAgent::where('agent_id', $agentId)->firstOrFail();
        $config = $this->agentService->getConfig($agent);
        $configHistory = EndpointAgentConfig::where('agent_id', $agent->id)
            ->orderByDesc('applied_at')
            ->limit(10)
            ->get();

        return view('endpoint.agent-config-policy', compact('agent', 'config', 'configHistory'));
    }

    /**
     * Update config policy for an agent.
     */
    public function updateConfig(Request $request, string $agentId): RedirectResponse
    {
        $agent = EndpointAgent::where('agent_id', $agentId)->firstOrFail();

        $telemetry = [
            'process'         => (bool) $request->input('telemetry_process', false),
            'network'         => (bool) $request->input('telemetry_network', false),
            'dns'             => (bool) $request->input('telemetry_dns', false),
            'file'            => (bool) $request->input('telemetry_file', false),
            'scheduled_tasks' => (bool) $request->input('telemetry_scheduled_tasks', false),
            'services'        => (bool) $request->input('telemetry_services', false),
        ];

        $watchPathsRaw = $request->input('allowed_watch_paths', '');
        $watchPaths    = array_values(array_filter(
            array_map('trim', explode("\n", $watchPathsRaw))
        ));

        $config = [
            'policy_version'            => '1.0.0',
            'telemetry'                 => $telemetry,
            'batch_interval_seconds'    => (int) $request->input('batch_interval_seconds', 30),
            'buffer_size'               => (int) $request->input('buffer_size', 1000),
            'max_buffer_size'           => (int) $request->input('max_buffer_size', 5000),
            'heartbeat_interval_seconds'=> (int) $request->input('heartbeat_interval_seconds', 60),
            'allowed_watch_paths'       => $watchPaths,
            'disk_pressure_threshold_mb'=> (int) $request->input('disk_pressure_threshold_mb', 100),
        ];

        $this->agentService->updateConfig($agent, $config, $request->user()?->id);

        return redirect()->route('endpoint.agent.config', $agentId)
            ->with('success', 'Config policy updated for ' . $agent->hostname);
    }
}
