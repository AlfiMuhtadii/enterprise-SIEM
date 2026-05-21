<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Idempotency Validation Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1">Reliability controls are operational safeguards only. No autonomous remediation, destructive replay, or hidden data deletion is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">Total Records</div>
                <div class="text-2xl font-bold text-cyan-300">{{ $records->count() }}</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">Duplicates Detected</div>
                <div class="text-2xl font-bold text-red-300">{{ $duplicates->count() }}</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">Clean Rate</div>
                <div class="text-2xl font-bold text-green-300">
                    @if($records->count() > 0)
                    {{ number_format((1 - $duplicates->count() / $records->count()) * 100, 1) }}%
                    @else 100.0% @endif
                </div>
            </div>
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Recent Idempotency Records</h3>
            @forelse($records->take(50) as $r)
            <div class="py-1.5 border-b border-gray-800 last:border-0 text-xs flex items-center gap-2">
                <span class="font-mono text-gray-500 truncate w-20">{{ substr($r->event_fingerprint, 0, 12) }}…</span>
                <span class="text-gray-400 w-24">{{ $r->source_topic }}</span>
                <span class="px-1.5 py-0.5 rounded
                    @if($r->processing_state === 'processed') bg-green-900/40 text-green-300
                    @elseif($r->processing_state === 'duplicate_detected') bg-red-900/40 text-red-300
                    @elseif($r->processing_state === 'replayed_safe') bg-blue-900/40 text-blue-300
                    @else bg-gray-700 text-gray-400 @endif">{{ $r->processing_state }}</span>
                @if($r->replay_count > 0)<span class="text-yellow-400">replays: {{ $r->replay_count }}</span>@endif
                @if($r->corruption_risk)<span class="text-red-400 font-bold">⚠ corruption risk</span>@endif
            </div>
            @empty
            <p class="text-xs text-gray-500">No idempotency records.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
