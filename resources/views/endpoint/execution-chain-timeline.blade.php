<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Execution Chain Timeline</h2>
                <p class="text-xs text-amber-400/80 mt-1 font-medium">Behavioral analytics are advisory-only and shadow-mode.</p>
                <p class="text-xs text-cyan-400/50 mt-0.5">{{ $agent->hostname ?? $agent->agent_id }}</p>
            </div>
            <a href="{{ route('endpoint.analytics.dashboard', $agent->agent_id) }}"
               class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">← Dashboard</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (empty($chains))
                <div class="glass-card p-6">
                    <p class="text-cyan-400/50 text-sm">No suspicious execution chains detected in recent snapshots.</p>
                </div>
            @else
                @foreach ($chains as $chain)
                <div class="glass-card p-6">
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <span class="font-mono text-xs text-cyan-400/50">{{ $chain['chain_id'] }}</span>
                            <span class="ml-3 text-xs text-cyan-400/60">depth: {{ $chain['chain_length'] }}</span>
                            <span class="ml-3 text-xs font-bold {{ $chain['chain_score'] >= 0.8 ? 'text-red-300' : ($chain['chain_score'] >= 0.6 ? 'text-orange-300' : 'text-yellow-300') }}">
                                score: {{ number_format($chain['chain_score'] * 100) }}%
                            </span>
                        </div>
                        <div class="flex gap-2 text-xs">
                            @if ($chain['involves_shell'])    <span class="px-1.5 py-0.5 rounded bg-yellow-900/40 text-yellow-300">shell</span> @endif
                            @if ($chain['involves_outbound']) <span class="px-1.5 py-0.5 rounded bg-red-900/40 text-red-300">outbound</span> @endif
                            @if ($chain['involves_persistence']) <span class="px-1.5 py-0.5 rounded bg-orange-900/40 text-orange-300">persistence</span> @endif
                        </div>
                    </div>
                    <div class="flex items-center flex-wrap gap-1">
                        @foreach ($chain['chain_steps'] as $i => $step)
                            @if ($i > 0) <span class="text-cyan-400/30">→</span> @endif
                            <span class="font-mono text-xs px-2 py-1 rounded {{ $step['is_shell'] ? 'bg-yellow-900/30 text-yellow-300' : 'bg-cyan-900/30 text-cyan-200' }}">
                                {{ $step['process_name'] }}
                                @if ($step['pid'] ?? false) <span class="text-cyan-400/40">[{{ $step['pid'] }}]</span> @endif
                            </span>
                        @endforeach
                    </div>
                    <p class="text-xs text-cyan-400/40 mt-2">{{ $chain['detected_at'] ? \Carbon\Carbon::parse($chain['detected_at'])->diffForHumans() : '—' }}</p>
                </div>
                @endforeach
            @endif

            <div class="glass-card p-4 border border-amber-400/20">
                <p class="text-xs text-amber-300/80">Advisory-only. No process action is taken on detected chains. Chains require analyst review.</p>
            </div>
        </div>
    </div>
</x-app-layout>
