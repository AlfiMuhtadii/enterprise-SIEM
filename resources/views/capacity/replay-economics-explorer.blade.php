<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Replay Economics Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1">Capacity and performance governance are operational visibility controls only. No autonomous scaling, destructive retention purge, or hidden infrastructure mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        @if($unbounded->count() > 0)
        <div class="rounded border border-red-500/40 bg-red-900/10 px-4 py-3 text-sm text-red-300">
            <strong>⚠ Unbounded Replay:</strong> {{ $unbounded->count() }} replay run(s) exceeded the safe amplification ratio ({{ \App\Services\CapacityGovernanceService::SAFE_AMPLIFICATION_RATIO }}×).
        </div>
        @endif

        <div class="grid grid-cols-3 gap-4 text-xs">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400">Total Runs</div>
                <div class="text-2xl font-bold text-cyan-300">{{ $runs->count() }}</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400">Avg Amplification</div>
                <div class="text-2xl font-bold text-orange-300">{{ number_format($runs->avg('replay_amplification_ratio') ?? 0, 2) }}×</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400">Bounded</div>
                <div class="text-2xl font-bold text-green-300">{{ $runs->where('is_bounded', true)->count() }}</div>
            </div>
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Replay Economics Runs ({{ $runs->count() }})</h3>
            @forelse($runs as $run)
            <div class="py-2 border-b border-gray-800 last:border-0 text-xs">
                <div class="flex items-center gap-2">
                    <span class="text-cyan-300">{{ $run->consumer_group ?? '—' }}</span>
                    <span class="text-gray-400">{{ $run->topic ?? '—' }}</span>
                    <span class="px-1.5 py-0.5 rounded
                        @if($run->concurrency_state === 'at_limit') bg-red-900/40 text-red-300
                        @elseif($run->concurrency_state === 'approaching_limit') bg-yellow-900/40 text-yellow-300
                        @elseif($run->concurrency_state === 'elevated') bg-orange-900/40 text-orange-300
                        @else bg-green-900/40 text-green-300 @endif">{{ $run->concurrency_state }}</span>
                    <span class="text-orange-300">{{ number_format($run->replay_amplification_ratio, 2) }}×</span>
                    @if(!$run->is_bounded)<span class="text-red-400 font-bold">UNBOUNDED</span>@endif
                </div>
                <div class="text-gray-500 mt-0.5">
                    {{ number_format($run->events_replayed) }} events · {{ $run->replay_duration_seconds }}s · cost: {{ number_format($run->replay_cost_estimate, 4) }} units
                </div>
            </div>
            @empty
            <p class="text-xs text-gray-500">No replay economics runs.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
