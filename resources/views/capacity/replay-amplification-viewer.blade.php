<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Replay Amplification Viewer</h2>
        <p class="text-xs text-amber-400/80 mt-1">Capacity and performance governance are operational visibility controls only. No autonomous scaling, destructive retention purge, or hidden infrastructure mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="grid grid-cols-3 gap-4 text-xs">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400">Total Reports</div>
                <div class="text-2xl font-bold text-cyan-300">{{ $reports->count() }}</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400">Avg Ratio</div>
                <div class="text-2xl font-bold text-orange-300">{{ number_format($avgRatio, 2) }}×</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400">Exceeded Threshold</div>
                <div class="text-2xl font-bold text-red-300">{{ $exceeded->count() }}</div>
            </div>
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Amplification Reports</h3>
            @forelse($reports as $r)
            <div class="py-2 border-b border-gray-800 last:border-0 text-xs">
                <div class="flex items-center gap-2">
                    <span class="text-cyan-300">{{ $r->consumer_group ?? '—' }}</span>
                    <span class="text-gray-400">{{ $r->topic ?? '—' }}</span>
                    <span class="text-orange-300 font-medium">{{ number_format($r->amplification_ratio, 2) }}×</span>
                    @if($r->exceeds_safe_threshold)
                    <span class="px-1.5 py-0.5 rounded bg-red-900/40 text-red-300">EXCEEDS {{ $r->safe_threshold }}× threshold</span>
                    @endif
                </div>
                <div class="text-gray-500 mt-0.5">
                    {{ number_format($r->original_event_count) }} orig → {{ number_format($r->replayed_event_count) }} replayed · {{ number_format($r->storage_amplification_mb, 2) }} MB
                </div>
            </div>
            @empty
            <p class="text-xs text-gray-500">No amplification reports.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
