<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">
            Endpoint Agent Inventory
            <span class="text-xs text-cyan-400/50 font-normal ml-2">shadow-only — no active enforcement</span>
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Summary stats --}}
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
                @foreach ([
                    ['label' => 'Total',    'value' => $summary['total'],    'color' => 'cyan'],
                    ['label' => 'Online',   'value' => $summary['online'],   'color' => 'green'],
                    ['label' => 'Degraded', 'value' => $summary['degraded'], 'color' => 'yellow'],
                    ['label' => 'Stale',    'value' => $summary['stale'],    'color' => 'orange'],
                    ['label' => 'Offline',  'value' => $summary['offline'],  'color' => 'red'],
                ] as $s)
                <div class="glass-card p-4">
                    <div class="text-2xl font-bold text-{{ $s['color'] }}-300">{{ $s['value'] }}</div>
                    <div class="text-xs text-cyan-400/70 mt-1">{{ $s['label'] }}</div>
                </div>
                @endforeach
            </div>

            {{-- Agent table --}}
            <div class="glass-card p-6">
                <h3 class="text-lg font-semibold text-cyan-100 mb-4">Enrolled Agents</h3>
                @if ($agents->isEmpty())
                    <p class="text-cyan-400/50 text-sm">No enrolled agents. Agents enroll via <code class="font-mono bg-cyan-900/20 px-1">POST /api/agents/enroll</code>.</p>
                @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-cyan-200">
                        <thead>
                            <tr class="border-b border-cyan-100/20 text-cyan-400 text-xs uppercase">
                                <th class="pb-2 text-left">Agent ID</th>
                                <th class="pb-2 text-left">Hostname</th>
                                <th class="pb-2 text-center">Health</th>
                                <th class="pb-2 text-left">Version</th>
                                <th class="pb-2 text-left">Platform</th>
                                <th class="pb-2 text-right">Events/s</th>
                                <th class="pb-2 text-right">Dropped</th>
                                <th class="pb-2 text-right">Last Seen</th>
                                <th class="pb-2 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyan-100/10">
                            @foreach ($agents as $agent)
                            <tr class="hover:bg-cyan-900/20">
                                <td class="py-2 font-mono text-xs text-cyan-400/80">
                                    {{ Str::limit($agent->agent_id, 22) }}
                                </td>
                                <td class="py-2 text-xs">{{ $agent->hostname ?? $agent->host_id }}</td>
                                <td class="py-2 text-center">
                                    @php $hs = $agent->health_state; @endphp
                                    <span class="px-2 py-0.5 rounded text-xs font-mono
                                        @if($hs === 'online')   bg-green-900/40 text-green-300
                                        @elseif($hs === 'degraded') bg-yellow-900/40 text-yellow-300
                                        @elseif($hs === 'stale')    bg-orange-900/40 text-orange-300
                                        @else                       bg-red-900/40 text-red-300 @endif">
                                        {{ $hs }}
                                    </span>
                                </td>
                                <td class="py-2 font-mono text-xs text-cyan-400/70">{{ $agent->agent_version ?? '—' }}</td>
                                <td class="py-2 text-xs text-cyan-400/70">{{ $agent->platform ?? '—' }}</td>
                                <td class="py-2 text-right font-mono text-xs">
                                    {{ $agent->metrics ? number_format($agent->metrics->events_per_sec, 1) : '—' }}
                                </td>
                                <td class="py-2 text-right font-mono text-xs {{ ($agent->metrics?->dropped_events ?? 0) > 0 ? 'text-yellow-300' : 'text-cyan-400/70' }}">
                                    {{ $agent->metrics ? number_format($agent->metrics->dropped_events) : '—' }}
                                </td>
                                <td class="py-2 text-right text-xs text-cyan-400/70 whitespace-nowrap">
                                    {{ $agent->last_seen_at?->diffForHumans() ?? 'Never' }}
                                </td>
                                <td class="py-2 text-center">
                                    <a href="{{ route('endpoint.agent.detail', $agent->agent_id) }}"
                                       class="text-xs text-cyan-400 hover:text-cyan-200">Detail</a>
                                    <span class="text-cyan-600 mx-1">·</span>
                                    <a href="{{ route('endpoint.agent.config', $agent->agent_id) }}"
                                       class="text-xs text-cyan-400 hover:text-cyan-200">Config</a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>

            <div class="glass-card p-4">
                <p class="text-xs text-cyan-400/50">
                    Endpoint telemetry is <strong class="text-yellow-300">shadow-only</strong>.
                    Agent observations feed the entity graph and risk scoring — they do not generate active SOC alerts.
                    See <a href="{{ route('endpoint.index') }}" class="text-cyan-400 hover:text-cyan-200">Endpoint Timeline</a> for shadow alert history.
                </p>
            </div>

        </div>
    </div>
</x-app-layout>
