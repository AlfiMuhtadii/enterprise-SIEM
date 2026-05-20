<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">SOAR Simulation Artifact Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1">SOAR workflows are simulation-first and approval-gated. No autonomous enforcement or destructive response is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="grid grid-cols-3 gap-4">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">Total Artifacts</div>
                <div class="text-2xl font-bold text-cyan-300">{{ $artifacts->count() }}</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">Avg Blast Radius</div>
                <div class="text-2xl font-bold text-orange-300">{{ number_format($avgBlast, 2) }}</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">Rollback Ready</div>
                <div class="text-2xl font-bold text-green-300">{{ $artifacts->where('rollback_ready', true)->count() }}</div>
            </div>
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Simulation Artifacts ({{ $artifacts->count() }})</h3>
            <div class="space-y-2">
                @forelse($artifacts as $art)
                <div class="py-2 border-b border-gray-800 last:border-0 text-xs">
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-gray-500">{{ $art->sim_result_id }}</span>
                        <span class="text-orange-300">blast: {{ number_format($art->blast_radius_score, 2) }}</span>
                        <span class="text-gray-400">{{ $art->estimated_affected_entities }} entities</span>
                        @if($art->rollback_ready)
                        <span class="text-green-400">ROLLBACK-READY</span>
                        @endif
                        <span class="ml-auto text-gray-500">{{ $art->created_at?->format('Y-m-d H:i') ?? '—' }}</span>
                    </div>
                    @if($art->warnings)
                    <div class="text-yellow-400/70 mt-0.5">{{ count($art->warnings) }} warning(s)</div>
                    @endif
                </div>
                @empty
                <p class="text-xs text-gray-500">No simulation artifacts yet.</p>
                @endforelse
            </div>
        </div>

    </div>
</x-app-layout>
