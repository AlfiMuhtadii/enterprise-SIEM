<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">
                    Agent: {{ $agent->hostname ?? $agent->host_id }}
                </h2>
                <p class="text-xs text-cyan-400/50 mt-1 font-mono">{{ $agent->agent_id }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('endpoint.agent.config', $agent->agent_id) }}"
                   class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">
                    Config Policy
                </a>
                <a href="{{ route('endpoint.agent.inventory') }}"
                   class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">
                    ← Inventory
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Health & Enrollment Summary --}}
            <div class="glass-card p-6">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div>
                        <div class="text-xs text-cyan-400/70 mb-1">Health State</div>
                        @php $hs = $agent->health_state; @endphp
                        <span class="px-3 py-1 rounded text-sm font-mono
                            @if($hs === 'online')    bg-green-900/40 text-green-300
                            @elseif($hs === 'degraded') bg-yellow-900/40 text-yellow-300
                            @elseif($hs === 'stale')    bg-orange-900/40 text-orange-300
                            @else                       bg-red-900/40 text-red-300 @endif">
                            {{ strtoupper($hs) }}
                        </span>
                    </div>
                    <div>
                        <div class="text-xs text-cyan-400/70 mb-1">Version</div>
                        <div class="text-sm font-mono text-cyan-100">{{ $agent->agent_version ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-cyan-400/70 mb-1">Enrolled At</div>
                        <div class="text-sm text-cyan-100">{{ $agent->enrolled_at?->format('Y-m-d H:i') ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-cyan-400/70 mb-1">Last Seen</div>
                        <div class="text-sm text-cyan-100">{{ $agent->last_seen_at?->diffForHumans() ?? 'Never' }}</div>
                    </div>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mt-4 pt-4 border-t border-cyan-100/10">
                    <div>
                        <div class="text-xs text-cyan-400/70 mb-1">Host ID</div>
                        <div class="text-xs font-mono text-cyan-300/70">{{ $agent->host_id }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-cyan-400/70 mb-1">Platform</div>
                        <div class="text-xs text-cyan-300/70">{{ $agent->platform ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-cyan-400/70 mb-1">IP Address</div>
                        <div class="text-xs font-mono text-cyan-300/70">{{ $agent->ip_address ?? '—' }}</div>
                    </div>
                </div>
            </div>

            {{-- Telemetry Quality Metrics --}}
            @if ($agent->metrics)
            <div class="glass-card p-6">
                <h3 class="text-lg font-semibold text-cyan-100 mb-4">Telemetry Quality Metrics</h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    @foreach ([
                        ['label' => 'Events/sec',        'value' => number_format($agent->metrics->events_per_sec, 2), 'warn' => false],
                        ['label' => 'Dropped Events',    'value' => number_format($agent->metrics->dropped_events),   'warn' => $agent->metrics->dropped_events > 0],
                        ['label' => 'Retry Count',       'value' => number_format($agent->metrics->retry_count),      'warn' => $agent->metrics->retry_count > 10],
                        ['label' => 'Buffer Depth',      'value' => number_format($agent->metrics->buffer_depth),     'warn' => $agent->metrics->buffer_depth > 800],
                        ['label' => 'Total Sent',        'value' => number_format($agent->metrics->total_sent),       'warn' => false],
                        ['label' => 'Collection Cycles', 'value' => number_format($agent->metrics->collection_cycles),'warn' => false],
                    ] as $m)
                    <div class="p-3 rounded bg-cyan-900/20 border border-cyan-100/10">
                        <div class="text-xl font-bold {{ $m['warn'] ? 'text-yellow-300' : 'text-cyan-100' }}">{{ $m['value'] }}</div>
                        <div class="text-xs text-cyan-400/70 mt-1">{{ $m['label'] }}</div>
                    </div>
                    @endforeach
                </div>
                @if ($agent->metrics->last_successful_send)
                <p class="text-xs text-cyan-400/50 mt-3">
                    Last successful send: {{ $agent->metrics->last_successful_send->diffForHumans() }}
                </p>
                @endif
            </div>
            @endif

            {{-- Active Config --}}
            <div class="glass-card p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-cyan-100">Active Config Policy</h3>
                    <a href="{{ route('endpoint.agent.config', $agent->agent_id) }}"
                       class="text-xs text-cyan-400 hover:text-cyan-200">Edit →</a>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
                    @php $tel = $config['telemetry'] ?? []; @endphp
                    @foreach (['process', 'network', 'dns', 'file', 'scheduled_tasks', 'services'] as $toggle)
                    <div class="flex items-center gap-2">
                        <span class="{{ ($tel[$toggle] ?? false) ? 'text-green-400' : 'text-red-400/60' }} text-base">
                            {{ ($tel[$toggle] ?? false) ? '●' : '○' }}
                        </span>
                        <span class="text-cyan-300/70 text-xs capitalize">{{ str_replace('_', ' ', $toggle) }}</span>
                    </div>
                    @endforeach
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4 pt-3 border-t border-cyan-100/10 text-xs text-cyan-400/70">
                    <div>Batch: {{ $config['batch_interval_seconds'] ?? 30 }}s</div>
                    <div>Buffer: {{ $config['buffer_size'] ?? 1000 }} / {{ $config['max_buffer_size'] ?? 5000 }}</div>
                    <div>Heartbeat: {{ $config['heartbeat_interval_seconds'] ?? 60 }}s</div>
                    <div>Disk guard: {{ $config['disk_pressure_threshold_mb'] ?? 100 }}MB</div>
                </div>
            </div>

            {{-- Heartbeat History --}}
            @if ($recentHeartbeats->isNotEmpty())
            <div class="glass-card p-6">
                <h3 class="text-lg font-semibold text-cyan-100 mb-4">Recent Heartbeats</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-cyan-200">
                        <thead>
                            <tr class="border-b border-cyan-100/20 text-cyan-400 text-xs uppercase">
                                <th class="pb-2 text-left">Time</th>
                                <th class="pb-2 text-center">Sig Valid</th>
                                <th class="pb-2 text-center">Health</th>
                                <th class="pb-2 text-right">Buffer</th>
                                <th class="pb-2 text-right">Dropped</th>
                                <th class="pb-2 text-left">IP</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyan-100/10">
                            @foreach ($recentHeartbeats as $hb)
                            <tr class="hover:bg-cyan-900/20">
                                <td class="py-2 text-xs text-cyan-400/70 whitespace-nowrap">
                                    {{ $hb->heartbeat_at?->format('m-d H:i:s') }}
                                </td>
                                <td class="py-2 text-center text-xs">
                                    {{ $hb->signature_valid ? '✓' : '✗' }}
                                </td>
                                <td class="py-2 text-center">
                                    <span class="text-xs font-mono {{ $hb->health_state === 'online' ? 'text-green-400' : 'text-yellow-400' }}">
                                        {{ $hb->health_state ?? '—' }}
                                    </span>
                                </td>
                                <td class="py-2 text-right font-mono text-xs">
                                    {{ $hb->metrics['buffer_depth'] ?? '—' }}
                                </td>
                                <td class="py-2 text-right font-mono text-xs {{ ($hb->metrics['dropped_events'] ?? 0) > 0 ? 'text-yellow-300' : 'text-cyan-400/70' }}">
                                    {{ $hb->metrics['dropped_events'] ?? '—' }}
                                </td>
                                <td class="py-2 font-mono text-xs text-cyan-400/70">{{ $hb->ip_address ?? '—' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

        </div>
    </div>
</x-app-layout>
