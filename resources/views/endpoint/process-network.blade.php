<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Process-Network Correlation</h2>
                <p class="text-xs text-amber-400/80 mt-1 font-medium">Shadow-only endpoint visibility. No active containment.</p>
                <p class="text-xs text-cyan-400/50 mt-0.5">{{ $agent->hostname ?? $agent->agent_id }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('endpoint.behavioral.persistence', $agent->agent_id) }}"
                   class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">Persistence</a>
                <a href="{{ route('endpoint.behavioral.activity', $agent->agent_id) }}"
                   class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">← Timeline</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="glass-card p-6">
                <h3 class="text-sm font-semibold text-cyan-200 mb-4">Process-to-Network Links (latest snapshot)</h3>
                <p class="text-xs text-cyan-400/40 mb-3">
                    Correlations are approximate (UID-based linkage from /proc/net/tcp). Confidence reflects linkage quality.
                </p>
                @if (empty($correlations))
                    <p class="text-cyan-400/50 text-sm">No process-network correlation data available.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-cyan-200/80">
                            <thead>
                                <tr class="text-left text-xs text-cyan-400/60 border-b border-cyan-200/10">
                                    <th class="pb-2 pr-4">PID</th>
                                    <th class="pb-2 pr-4">Process</th>
                                    <th class="pb-2 pr-4">Remote IP</th>
                                    <th class="pb-2 pr-4">Port</th>
                                    <th class="pb-2 pr-4">Proto</th>
                                    <th class="pb-2">Confidence</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($correlations as $corr)
                                <tr class="border-b border-cyan-200/5 hover:bg-cyan-100/5">
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $corr['pid'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs font-medium text-cyan-100">{{ $corr['process_name'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $corr['remote_ip'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $corr['remote_port'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-xs uppercase">{{ $corr['proto'] ?? '—' }}</td>
                                    <td class="py-2 text-xs">
                                        @php $conf = $corr['correlation_confidence'] ?? 0; @endphp
                                        <span class="{{ $conf >= 0.8 ? 'text-green-300' : ($conf >= 0.5 ? 'text-yellow-300' : 'text-cyan-400/60') }}">
                                            {{ number_format($conf * 100) }}%
                                        </span>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="glass-card p-4 border border-amber-400/20">
                <p class="text-xs text-amber-300/80">
                    Shadow-only endpoint visibility. Network correlation data is for investigation only.
                    No connection blocking, firewall modification, or process termination is implemented.
                </p>
            </div>

        </div>
    </div>
</x-app-layout>
