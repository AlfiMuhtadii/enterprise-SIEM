<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Execution Chain Explorer</h2>
                <p class="text-xs text-amber-400/80 mt-1 font-medium">Threat hunting is advisory-only and non-destructive.</p>
            </div>
            <a href="{{ route('threat-hunt.dashboard') }}" class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">← Dashboard</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="glass-card p-4">
                <form method="GET" action="{{ route('threat-hunt.chain-explorer') }}" class="flex gap-3 items-end">
                    <div class="flex-1">
                        <label class="block text-xs text-cyan-400/70 mb-1">Select Agent</label>
                        <select name="agent_id" class="w-full text-sm px-3 py-2 rounded bg-black/30 border border-cyan-200/20 text-cyan-200">
                            <option value="">— Select —</option>
                            @foreach ($agents as $agent)
                                <option value="{{ $agent->agent_id }}" {{ $agentId === $agent->agent_id ? 'selected' : '' }}>
                                    {{ $agent->hostname ?? $agent->agent_id }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="px-4 py-2 rounded bg-cyan-700/30 border border-cyan-400/30 text-cyan-200 hover:bg-cyan-700/50 text-sm">Explore</button>
                </form>
            </div>

            @if ($agentId)
                <div class="glass-card p-6">
                    <h3 class="text-sm font-semibold text-cyan-200 mb-4">Execution Chains ({{ count($chains) }})</h3>
                    @if (empty($chains))
                        <p class="text-cyan-400/50 text-sm">No execution chains detected for this agent.</p>
                    @else
                        <div class="space-y-4">
                            @foreach ($chains as $chain)
                            <div class="border border-cyan-200/10 rounded p-4">
                                <div class="flex items-center gap-3 mb-2">
                                    <span class="font-mono text-xs text-cyan-400/50">{{ $chain['chain_id'] }}</span>
                                    <span class="text-xs font-bold {{ $chain['chain_score'] >= 0.8 ? 'text-red-300' : 'text-orange-300' }}">score: {{ number_format($chain['chain_score']*100) }}%</span>
                                    <span class="text-xs text-cyan-400/50">depth: {{ $chain['chain_length'] }}</span>
                                    @if ($chain['involves_outbound']) <span class="px-1 py-0.5 rounded bg-red-900/30 text-red-300 text-xs">outbound</span> @endif
                                    @if ($chain['involves_shell']) <span class="px-1 py-0.5 rounded bg-yellow-900/30 text-yellow-300 text-xs">shell</span> @endif
                                </div>
                                <div class="flex items-center flex-wrap gap-1">
                                    @foreach ($chain['chain_steps'] as $i => $step)
                                        @if ($i > 0) <span class="text-cyan-400/30 text-xs">→</span> @endif
                                        <span class="font-mono text-xs px-2 py-1 rounded {{ ($step['is_shell'] ?? false) ? 'bg-yellow-900/30 text-yellow-300' : 'bg-cyan-900/30 text-cyan-200' }}">{{ $step['process_name'] }}</span>
                                    @endforeach
                                </div>
                                <div class="text-xs text-cyan-400/40 mt-1">{{ $chain['detected_at'] ? \Carbon\Carbon::parse($chain['detected_at'])->diffForHumans() : '—' }}</div>
                            </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            <div class="glass-card p-4 border border-amber-400/20">
                <p class="text-xs text-amber-300/80">Advisory-only chain explorer. No process actions are taken. Chains require analyst review.</p>
            </div>
        </div>
    </div>
</x-app-layout>
