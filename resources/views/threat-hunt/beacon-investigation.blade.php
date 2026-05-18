<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Beacon Investigation</h2>
                <p class="text-xs text-amber-400/80 mt-1 font-medium">Threat hunting is advisory-only and non-destructive.</p>
            </div>
            <a href="{{ route('threat-hunt.dashboard') }}" class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">← Dashboard</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="glass-card p-4">
                <form method="GET" action="{{ route('threat-hunt.beacon-investigation') }}" class="flex gap-3 items-end">
                    <div class="flex-1">
                        <label class="block text-xs text-cyan-400/70 mb-1">Select Agent</label>
                        <select name="agent_id" class="w-full text-sm px-3 py-2 rounded bg-black/30 border border-cyan-200/20 text-cyan-200">
                            <option value="">— All agents —</option>
                            @foreach ($agents as $agent)
                                <option value="{{ $agent->agent_id }}" {{ $agentId === $agent->agent_id ? 'selected' : '' }}>
                                    {{ $agent->hostname ?? $agent->agent_id }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="px-4 py-2 rounded bg-cyan-700/30 border border-cyan-400/30 text-cyan-200 hover:bg-cyan-700/50 text-sm">Investigate</button>
                </form>
            </div>

            @if ($agentId)
                <div class="glass-card p-6">
                    <h3 class="text-sm font-semibold text-cyan-200 mb-4">Beacon Patterns ({{ count($patterns) }})</h3>
                    @if (empty($patterns))
                        <p class="text-cyan-400/50 text-sm">No beacon patterns detected for this agent.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm text-cyan-200/80">
                                <thead>
                                    <tr class="text-left text-xs text-cyan-400/60 border-b border-cyan-200/10">
                                        <th class="pb-2 pr-4">Pattern ID</th><th class="pb-2 pr-4">Process</th>
                                        <th class="pb-2 pr-4">Destination</th><th class="pb-2 pr-4">Count</th>
                                        <th class="pb-2">Reuse Score</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($patterns as $p)
                                    <tr class="border-b border-cyan-200/5 hover:bg-cyan-100/5">
                                        <td class="py-2 pr-4 font-mono text-xs">{{ $p['pattern_id'] }}</td>
                                        <td class="py-2 pr-4 font-mono text-xs text-yellow-300">{{ $p['process_name'] }}</td>
                                        <td class="py-2 pr-4 font-mono text-xs">{{ $p['remote_ip'] }}:{{ $p['remote_port'] }}</td>
                                        <td class="py-2 pr-4 text-xs {{ ($p['connection_count'] ?? 0) >= 5 ? 'text-red-300' : 'text-orange-300' }}">{{ $p['connection_count'] }}</td>
                                        <td class="py-2 text-xs">{{ number_format(($p['destination_reuse_score'] ?? 0)*100) }}%</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif

            <div class="glass-card p-4 border border-amber-400/20">
                <p class="text-xs text-amber-300/80">Advisory-only beacon investigation. No connection blocking, no process actions.</p>
            </div>
        </div>
    </div>
</x-app-layout>
