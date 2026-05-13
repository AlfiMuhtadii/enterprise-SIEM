<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SocAgentController extends Controller
{
    public function index(Request $request): View
    {
        $offlineAfter = (int) config('soc.agent_offline_after_seconds', 180);
        $agents = DB::table('endpoint_agents')
            ->orderByDesc('last_seen_at')
            ->paginate(25)
            ->withQueryString();
        $agents->getCollection()->transform(function ($agent) use ($offlineAfter) {
            $agent->computed_status = $agent->last_seen_at && now()->diffInSeconds($agent->last_seen_at) <= $offlineAfter ? 'online' : 'offline';
            return $agent;
        });

        return view('soc.agents.index', [
            'agents' => $agents,
            'policies' => DB::table('agent_policies')->orderByDesc('is_default')->orderBy('name')->get(),
            'commands' => DB::table('agent_commands')->orderByDesc('queued_at')->limit(100)->get(),
            'responses' => DB::table('soc_response_workflows')->orderByDesc('created_at')->limit(100)->get(),
            'forensicJobs' => DB::table('forensic_collection_jobs')->orderByDesc('created_at')->limit(100)->get(),
            'streamMetrics' => DB::table('agent_stream_metrics')->orderByDesc('reported_at')->limit(100)->get(),
            'releases' => DB::table('agent_releases')->orderByDesc('released_at')->get(),
            'tamperAlerts' => DB::table('security_alerts')
                ->whereIn('alert_type', ['AGENT_STALE_OR_STOPPED', 'AGENT_RETRY_QUEUE_GROWTH', 'AGENT_REPEATED_DELIVERY_FAILURE', 'AGENT_POLICY_OUTDATED', 'AGENT_STARTUP_INTEGRITY_FAILED', 'AGENT_UNEXPECTED_RESTART'])
                ->orderByDesc('detected_at')
                ->limit(50)
                ->get(),
            'latestVersion' => config('soc.agent_latest_version', '0.2.0'),
        ]);
    }

    public function storePolicy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'policy_id' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160'],
            'collection_interval_seconds' => ['required', 'integer', 'min:10', 'max:86400'],
            'max_batch_size' => ['required', 'integer', 'min:1', 'max:1000'],
            'collect_process' => ['nullable', 'boolean'],
            'collect_network' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $before = DB::table('agent_policies')->where('policy_id', $data['policy_id'])->first();
        if (!empty($data['is_default'])) {
            DB::table('agent_policies')->update(['is_default' => false]);
        }
        DB::table('agent_policies')->updateOrInsert(
            ['policy_id' => $data['policy_id']],
            [
                'name' => $data['name'],
                'version' => DB::raw($before ? 'version + 1' : '1'),
                'is_default' => !empty($data['is_default']),
                'collection_interval_seconds' => $data['collection_interval_seconds'],
                'enabled_collectors' => json_encode([
                    'process' => $request->boolean('collect_process'),
                    'network' => $request->boolean('collect_network'),
                ]),
                'max_batch_size' => $data['max_batch_size'],
                'retry_policy' => json_encode(['initial_backoff_seconds' => 1, 'max_backoff_seconds' => 300]),
                'telemetry_categories' => json_encode(['endpoint', 'network']),
                'metadata' => json_encode(['updated_from' => 'soc-ui']),
                'created_at' => $before->created_at ?? now(),
                'updated_at' => now(),
            ]
        );
        $after = DB::table('agent_policies')->where('policy_id', $data['policy_id'])->first();
        AuditLogger::log($request->user()->email, 'agent.policy.upsert', 'agent_policy', $data['policy_id'], $before, $after);

        return back()->with('status', 'Agent policy saved.');
    }

    public function assignPolicy(Request $request, string $agentId): RedirectResponse
    {
        $data = $request->validate(['policy_id' => ['required', 'string', 'max:80']]);
        $before = DB::table('endpoint_agents')->where('agent_id', $agentId)->first();
        abort_if(!$before, 404);
        DB::table('endpoint_agents')->where('agent_id', $agentId)->update([
            'policy_id' => $data['policy_id'],
            'updated_at' => now(),
        ]);
        $after = DB::table('endpoint_agents')->where('agent_id', $agentId)->first();
        AuditLogger::log($request->user()->email, 'agent.policy.assign', 'agent', $agentId, $before, $after);

        return back()->with('status', 'Policy assigned.');
    }

    public function queueCommand(Request $request, string $agentId): RedirectResponse
    {
        $data = $request->validate([
            'command_type' => ['required', 'in:collect-now,flush-local-queue,rotate-agent-secret,refresh-policy,restart-agent-loop'],
        ]);
        abort_unless(DB::table('endpoint_agents')->where('agent_id', $agentId)->exists(), 404);
        $commandId = 'cmd-'.Str::uuid()->toString();
        $payload = [];
        if ($data['command_type'] === 'rotate-agent-secret') {
            $payload['new_agent_secret'] = Str::random(48);
        }

        $row = [
            'command_id' => $commandId,
            'agent_id' => $agentId,
            'command_type' => $data['command_type'],
            'status' => 'queued',
            'payload' => json_encode($payload),
            'queued_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'created_by' => $request->user()->email,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('agent_commands')->insert($row);
        AuditLogger::log($request->user()->email, 'agent.command.queue', 'agent', $agentId, null, $row);

        return back()->with('status', 'Command queued.');
    }
}
