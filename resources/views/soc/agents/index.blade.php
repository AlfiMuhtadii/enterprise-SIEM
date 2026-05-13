<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="brand-chip">Endpoint Operations</p>
                <h2 class="mt-2 text-2xl font-semibold text-main-ui">Agent Management</h2>
            </div>
            <a href="{{ route('soc.dashboard') }}" class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Back to SOC</a>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-cyan-200/20 bg-cyan-100/10 p-3 text-sm text-cyan-50">{{ session('status') }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-4">
        <div class="metric-card"><p class="text-sm text-cyan-200/75">Agents</p><p class="mt-2 text-3xl font-semibold text-main-ui">{{ $agents->total() }}</p></div>
        <div class="metric-card"><p class="text-sm text-cyan-200/75">Policies</p><p class="mt-2 text-3xl font-semibold text-main-ui">{{ $policies->count() }}</p></div>
        <div class="metric-card"><p class="text-sm text-cyan-200/75">Latest Version</p><p class="mt-2 text-3xl font-semibold text-main-ui">{{ $latestVersion }}</p></div>
        <div class="metric-card"><p class="text-sm text-cyan-200/75">Tamper Alerts</p><p class="mt-2 text-3xl font-semibold text-main-ui">{{ $tamperAlerts->count() }}</p></div>
    </div>

    <section class="glass-card mt-4 overflow-hidden">
        <div class="border-b border-cyan-100/15 px-5 py-4"><h3 class="text-lg font-semibold text-main-ui">Agent Inventory</h3></div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-cyan-100/10 text-sm">
                <thead class="bg-black/20 text-left text-xs uppercase tracking-[0.12em] text-cyan-200/70"><tr><th class="px-4 py-3">Agent</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Version</th><th class="px-4 py-3">Policy</th><th class="px-4 py-3">Queue</th><th class="px-4 py-3">Command</th></tr></thead>
                <tbody class="divide-y divide-cyan-100/10">
                    @forelse ($agents as $agent)
                        @php $meta = json_decode($agent->metadata ?: '{}', true) ?: []; @endphp
                        <tr>
                            <td class="px-4 py-3"><p class="mono-ui text-xs text-cyan-50">{{ $agent->agent_id }}</p><p class="text-xs text-cyan-100/70">{{ $agent->host_id }} | {{ $agent->last_seen_at ?: '-' }}</p></td>
                            <td class="px-4 py-3 text-cyan-100">{{ $agent->computed_status }}<p class="text-xs text-cyan-100/50">{{ $agent->last_error ?: '' }}</p></td>
                            <td class="px-4 py-3 text-cyan-100">
                                {{ $agent->agent_version }}<p class="text-xs text-amber-200">{{ $agent->upgrade_status }}</p>
                                <p class="mt-1 text-xs {{ ($meta['startup_integrity_ok'] ?? true) ? 'text-emerald-200' : 'text-red-200' }}">integrity={{ ($meta['startup_integrity_ok'] ?? true) ? 'ok' : 'failed' }}</p>
                                <p class="text-xs text-cyan-100/50">restarts={{ $meta['unexpected_restart_count'] ?? 0 }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('soc.agents.assign-policy', $agent->agent_id) }}" class="flex gap-2">
                                    @csrf
                                    <select name="policy_id" class="rounded border border-cyan-200/30 bg-slate-950 px-2 py-1 text-xs text-cyan-50">
                                        @foreach ($policies as $policy)
                                            <option value="{{ $policy->policy_id }}" @selected($agent->policy_id === $policy->policy_id)>{{ $policy->name }} v{{ $policy->version }}</option>
                                        @endforeach
                                    </select>
                                    <button class="rounded border border-cyan-200/20 px-2 py-1 text-xs text-cyan-50">Assign</button>
                                </form>
                            </td>
                            <td class="px-4 py-3 mono-ui text-xs text-cyan-100">retry={{ $agent->retry_queue_depth }} errors={{ $agent->error_count_total }}</td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('soc.agents.command', $agent->agent_id) }}" class="flex gap-2">
                                    @csrf
                                    <select name="command_type" class="rounded border border-cyan-200/30 bg-slate-950 px-2 py-1 text-xs text-cyan-50">
                                        @foreach (['collect-now','flush-local-queue','rotate-agent-secret','refresh-policy','restart-agent-loop'] as $cmd)
                                            <option value="{{ $cmd }}">{{ $cmd }}</option>
                                        @endforeach
                                    </select>
                                    <button class="rounded border border-cyan-200/20 px-2 py-1 text-xs text-cyan-50">Queue</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-6 text-muted-ui" colspan="6">No agents enrolled.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-cyan-100/15 px-5 py-4">{{ $agents->links() }}</div>
    </section>

    <section class="glass-card mt-4 p-5">
        <h3 class="text-lg font-semibold text-main-ui">Policy Management</h3>
        <form method="POST" action="{{ route('soc.agents.policies.store') }}" class="mt-4 grid gap-3 md:grid-cols-6">
            @csrf
            <input name="policy_id" placeholder="policy-id" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
            <input name="name" placeholder="Policy name" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
            <input name="collection_interval_seconds" value="60" type="number" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
            <input name="max_batch_size" value="500" type="number" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
            <label class="text-sm text-cyan-100"><input name="collect_process" value="1" type="checkbox" checked> process</label>
            <label class="text-sm text-cyan-100"><input name="collect_network" value="1" type="checkbox" checked> network</label>
            <label class="text-sm text-cyan-100"><input name="is_default" value="1" type="checkbox"> default</label>
            <button class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50 md:col-span-2">Save Policy</button>
        </form>
    </section>

    <section class="mt-4 grid gap-4 xl:grid-cols-2">
        <div class="glass-card overflow-hidden">
            <div class="border-b border-cyan-100/15 px-5 py-4"><h3 class="text-lg font-semibold text-main-ui">Remote Command History</h3></div>
            <div class="space-y-2 p-5">
                @forelse ($commands as $command)
                    <div class="rounded border border-cyan-200/15 bg-black/20 p-3 text-sm text-cyan-100">
                        <p class="mono-ui text-xs text-cyan-50">{{ $command->command_id }} | {{ $command->command_type }} | {{ $command->status }}</p>
                        <p class="text-xs text-cyan-100/60">{{ $command->agent_id }} queued {{ $command->queued_at }} attempts={{ $command->attempts }}</p>
                    </div>
                @empty
                    <p class="text-sm text-muted-ui">No commands queued yet.</p>
                @endforelse
            </div>
        </div>
        <div class="glass-card overflow-hidden">
            <div class="border-b border-cyan-100/15 px-5 py-4"><h3 class="text-lg font-semibold text-main-ui">Tamper / Health Alerts</h3></div>
            <div class="space-y-2 p-5">
                @forelse ($tamperAlerts as $alert)
                    <div class="rounded border border-cyan-200/15 bg-black/20 p-3 text-sm text-cyan-100">
                        <p class="mono-ui text-xs text-cyan-50">{{ $alert->alert_type }} | {{ $alert->severity }}</p>
                        <p class="text-xs text-cyan-100/60">{{ $alert->actor_key }} | {{ $alert->detected_at }}</p>
                    </div>
                @empty
                    <p class="text-sm text-muted-ui">No agent tamper alerts.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section class="mt-4 grid gap-4 xl:grid-cols-2">
        <div class="glass-card overflow-hidden">
            <div class="border-b border-cyan-100/15 px-5 py-4"><h3 class="text-lg font-semibold text-main-ui">Streaming Health</h3></div>
            <div class="space-y-2 p-5">
                @forelse ($streamMetrics as $metric)
                    <div class="rounded border border-cyan-200/15 bg-black/20 p-3 text-sm text-cyan-100">
                        <p class="mono-ui text-xs text-cyan-50">{{ $metric->agent_id }} | {{ $metric->stream_status }} | {{ $metric->reported_at }}</p>
                        <p class="text-xs text-cyan-100/60">streamed={{ $metric->events_streamed_total }} dropped={{ $metric->events_dropped_total }} retries={{ $metric->stream_retry_total }} latency={{ $metric->avg_event_latency_ms }}ms buffer={{ $metric->buffer_depth }}</p>
                    </div>
                @empty
                    <p class="text-sm text-muted-ui">No stream metrics reported yet.</p>
                @endforelse
            </div>
        </div>
        <div class="glass-card overflow-hidden">
            <div class="border-b border-cyan-100/15 px-5 py-4"><h3 class="text-lg font-semibold text-main-ui">Response Workflow</h3></div>
            <div class="space-y-2 p-5">
                @forelse ($responses as $response)
                    <div class="rounded border border-cyan-200/15 bg-black/20 p-3 text-sm text-cyan-100">
                        <p class="mono-ui text-xs text-cyan-50">{{ $response->response_id }} | {{ $response->action_type }} | {{ $response->status }}</p>
                        <p class="text-xs text-cyan-100/60">{{ $response->source_type }}={{ $response->source_id }} agent={{ $response->agent_id ?: '-' }}</p>
                        @if ($response->execution_result)
                            <p class="mt-1 text-xs text-emerald-100">result={{ \Illuminate\Support\Str::limit($response->execution_result, 140) }}</p>
                        @endif
                        @if ($response->status === 'pending_approval')
                            <form method="POST" action="{{ route('soc.responses.decide', $response->response_id) }}" class="mt-2 flex gap-2">
                                @csrf
                                <button name="decision" value="approve" class="rounded border border-emerald-200/30 px-2 py-1 text-xs text-emerald-100">Approve</button>
                                <button name="decision" value="reject" class="rounded border border-red-200/30 px-2 py-1 text-xs text-red-100">Reject</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-muted-ui">No response workflows yet.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section class="glass-card mt-4 p-5">
        <h3 class="text-lg font-semibold text-main-ui">Recommend Safe Response</h3>
        <form method="POST" action="{{ route('soc.responses.recommend') }}" class="mt-4 grid gap-3 md:grid-cols-6">
            @csrf
            <select name="source_type" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50"><option value="incident">incident</option><option value="alert">alert</option></select>
            <input name="source_id" placeholder="incident_id or alert_id" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
            <input name="agent_id" placeholder="agent_id" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
            <select name="action_type" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                @foreach (['collect-now','refresh-policy','flush-local-queue','rotate-agent-secret','restart-agent-loop','isolate-host','block-ioc','policy-quarantine'] as $action)
                    <option value="{{ $action }}">{{ $action }}</option>
                @endforeach
            </select>
            <input name="reason" placeholder="reason" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
            <button class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Recommend</button>
        </form>
        <p class="mt-2 text-xs text-cyan-100/60">Containment actions are approval-gated simulations. They record the expected isolate/block/quarantine effect without changing network, DNS, firewall, or endpoint policy.</p>
    </section>

    <section class="glass-card mt-4 p-5">
        <h3 class="text-lg font-semibold text-main-ui">Forensic Collection</h3>
        <form method="POST" action="{{ route('soc.forensics.request') }}" class="mt-4 grid gap-3 md:grid-cols-5">
            @csrf
            <input name="agent_id" placeholder="agent_id" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
            <input name="host_id" placeholder="host_id" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
            <select name="collection_type" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                @foreach (['process-snapshot','network-snapshot','telemetry-snapshot','recent-alert-evidence','endpoint-diagnostics'] as $type)
                    <option value="{{ $type }}">{{ $type }}</option>
                @endforeach
            </select>
            <button class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Request Collection</button>
        </form>
        <div class="mt-4 grid gap-2">
            @forelse ($forensicJobs as $job)
                <div class="rounded border border-cyan-200/15 bg-black/20 p-3 text-sm text-cyan-100">
                    <p class="mono-ui text-xs text-cyan-50">{{ $job->job_id }} | {{ $job->collection_type }} | {{ $job->status }}</p>
                    <p class="text-xs text-cyan-100/60">agent={{ $job->agent_id ?: '-' }} host={{ $job->host_id ?: '-' }} artifact={{ $job->artifact_path ?: '-' }}</p>
                    @if ($job->status === 'pending_approval')
                        <form method="POST" action="{{ route('soc.forensics.decide', $job->job_id) }}" class="mt-2 flex gap-2">
                            @csrf
                            <button name="decision" value="approve" class="rounded border border-emerald-200/30 px-2 py-1 text-xs text-emerald-100">Approve</button>
                            <button name="decision" value="reject" class="rounded border border-red-200/30 px-2 py-1 text-xs text-red-100">Reject</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="text-sm text-muted-ui">No forensic collection jobs.</p>
            @endforelse
        </div>
    </section>
</x-app-layout>
