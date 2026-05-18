<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Rare Parent-Child View</h2>
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
                <h3 class="text-sm font-semibold text-cyan-200 mb-4">Rare Parent-Child Process Relationships</h3>
                @if (empty($findings))
                    <p class="text-cyan-400/50 text-sm">No rare parent-child relationships detected.</p>
                @else
                    <div class="space-y-3">
                        @foreach ($findings as $f)
                        <div class="border border-cyan-200/10 rounded p-4 hover:bg-cyan-100/5">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-medium text-orange-300">{{ $f['title'] }}</span>
                                <span class="text-xs font-mono text-cyan-400/50">{{ $f['finding_id'] }}</span>
                            </div>
                            <div class="flex items-center gap-3 text-xs mb-2">
                                <span class="font-mono px-2 py-1 rounded bg-red-900/30 text-red-300">{{ $f['evidence']['parent_process'] ?? '?' }}</span>
                                <span class="text-cyan-400/30">→</span>
                                <span class="font-mono px-2 py-1 rounded bg-yellow-900/30 text-yellow-300">{{ $f['evidence']['child_process'] ?? '?' }}</span>
                                <span class="ml-2 text-cyan-400/60">rarity: {{ number_format(($f['evidence']['rarity_score'] ?? 0) * 100) }}%</span>
                            </div>
                            @if (!empty($f['evidence']['command_line']))
                            <div class="text-xs text-cyan-400/50 font-mono truncate">cmd: {{ $f['evidence']['command_line'] }}</div>
                            @endif
                            <div class="text-xs text-cyan-400/40 mt-1">{{ $f['detected_at'] ? \Carbon\Carbon::parse($f['detected_at'])->diffForHumans() : '—' }}</div>
                        </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="glass-card p-4 border border-amber-400/20">
                <p class="text-xs text-amber-300/80">Advisory-only rarity scoring. These process pairs are anomalous in typical environments. No automated action taken.</p>
            </div>
        </div>
    </div>
</x-app-layout>
