<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Persistence Correlation View</h2>
                <p class="text-xs text-amber-400/80 mt-1 font-medium">Behavioral analytics are advisory-only and shadow-mode.</p>
                <p class="text-xs text-cyan-400/50 mt-0.5">{{ $agent->hostname ?? $agent->agent_id }}</p>
            </div>
            <a href="{{ route('endpoint.analytics.dashboard', $agent->agent_id) }}"
               class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">← Dashboard</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="glass-card p-6">
                <h3 class="text-sm font-semibold text-cyan-200 mb-1">Persistence + Outbound Correlations</h3>
                <p class="text-xs text-cyan-400/50 mb-4">Findings where persistence items co-exist with shell processes that have outbound network connections.</p>
                @if (empty($findings))
                    <p class="text-cyan-400/50 text-sm">No persistence correlation findings detected.</p>
                @else
                    <div class="space-y-4">
                        @foreach ($findings as $f)
                        <div class="border border-orange-400/20 rounded p-4">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-medium text-orange-300">{{ $f['title'] }}</span>
                                <span class="text-xs text-cyan-400/50 font-mono">{{ $f['finding_id'] }}</span>
                            </div>
                            <div class="grid grid-cols-2 gap-4 text-xs mt-2">
                                <div>
                                    <div class="text-cyan-400/60 mb-1">Persistence Items</div>
                                    @foreach ($f['evidence']['persistence_items'] ?? [] as $item)
                                        <div class="font-mono text-cyan-200/70">{{ $item }}</div>
                                    @endforeach
                                </div>
                                <div>
                                    <div class="text-cyan-400/60 mb-1">Shell Processes (with outbound)</div>
                                    @foreach ($f['evidence']['shell_processes'] ?? [] as $proc)
                                        <div class="font-mono text-yellow-300/80">{{ $proc }}</div>
                                    @endforeach
                                </div>
                            </div>
                            <div class="mt-2 text-xs text-cyan-400/40">
                                confidence: {{ number_format(($f['confidence'] ?? 0) * 100) }}%
                                — new persistence: {{ $f['evidence']['new_persistence_count'] ?? 0 }}
                                — outbound connections: {{ $f['evidence']['outbound_connections'] ?? 0 }}
                            </div>
                        </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="glass-card p-4 border border-amber-400/20">
                <p class="text-xs text-amber-300/80">
                    Advisory-only persistence correlation. This finding combines persistence inventory with network activity for investigation enrichment.
                    No persistence removal, service disabling, or connection blocking is implemented.
                </p>
            </div>
        </div>
    </div>
</x-app-layout>
