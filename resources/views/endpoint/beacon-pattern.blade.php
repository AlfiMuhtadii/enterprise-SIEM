<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Beacon Pattern View</h2>
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
                <h3 class="text-sm font-semibold text-cyan-200 mb-4">Detected Beacon-like Patterns</h3>
                @if (empty($patterns))
                    <p class="text-cyan-400/50 text-sm">No beacon-like patterns detected. Threshold: {{ 3 }} or more connections to the same destination.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-cyan-200/80">
                            <thead>
                                <tr class="text-left text-xs text-cyan-400/60 border-b border-cyan-200/10">
                                    <th class="pb-2 pr-4">Pattern ID</th>
                                    <th class="pb-2 pr-4">Process</th>
                                    <th class="pb-2 pr-4">Remote IP</th>
                                    <th class="pb-2 pr-4">Port</th>
                                    <th class="pb-2 pr-4">Count</th>
                                    <th class="pb-2 pr-4">Reuse Score</th>
                                    <th class="pb-2">Detected</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($patterns as $p)
                                <tr class="border-b border-cyan-200/5 hover:bg-cyan-100/5">
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $p['pattern_id'] }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs font-medium text-cyan-100">{{ $p['process_name'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $p['remote_ip'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $p['remote_port'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-xs {{ ($p['connection_count'] ?? 0) >= 5 ? 'text-red-300' : 'text-orange-300' }}">{{ $p['connection_count'] ?? 0 }}</td>
                                    <td class="py-2 pr-4 text-xs">{{ number_format(($p['destination_reuse_score'] ?? 0) * 100) }}%</td>
                                    <td class="py-2 text-xs text-cyan-400/60">{{ $p['detected_at'] ? \Carbon\Carbon::parse($p['detected_at'])->diffForHumans() : '—' }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="glass-card p-4 border border-amber-400/20">
                <p class="text-xs text-amber-300/80">Advisory-only beacon analysis. No connection blocking or process termination. Analyst review required before any action.</p>
            </div>
        </div>
    </div>
</x-app-layout>
