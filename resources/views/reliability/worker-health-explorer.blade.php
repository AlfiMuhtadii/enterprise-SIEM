<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Worker Health Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1">Reliability controls are operational safeguards only. No autonomous remediation, destructive replay, or hidden data deletion is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="grid grid-cols-5 gap-4 text-xs">
            @foreach(\App\Models\SystemWorkerHeartbeat::HEALTH_STATES as $state)
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400">{{ $state }}</div>
                <div class="text-xl font-bold text-cyan-300">{{ $byState->get($state)?->count() ?? 0 }}</div>
            </div>
            @endforeach
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Workers ({{ $workers->count() }})</h3>
            <div class="space-y-2">
                @forelse($workers as $w)
                <div class="py-2 border-b border-gray-800 last:border-0 text-xs">
                    <div class="flex items-center gap-2">
                        <span class="text-cyan-300 font-mono">{{ $w->worker_id }}</span>
                        <span class="text-gray-400">{{ $w->service_name }}</span>
                        <span class="px-1.5 py-0.5 rounded
                            @if($w->health_state === 'healthy') bg-green-900/40 text-green-300
                            @elseif($w->health_state === 'lagging') bg-yellow-900/40 text-yellow-300
                            @elseif(in_array($w->health_state, ['stalled','disconnected'])) bg-red-900/40 text-red-300
                            @else bg-orange-900/40 text-orange-300 @endif">{{ $w->health_state }}</span>
                        @if($w->is_stale)<span class="text-yellow-400">[STALE]</span>@endif
                        @if($w->is_stalled)<span class="text-red-400">[STALLED]</span>@endif
                    </div>
                    <div class="text-gray-500 mt-0.5">
                        lag: {{ number_format($w->current_lag) }} · processed: {{ number_format($w->processed_event_count) }} · failed: {{ $w->failed_event_count }} · last: {{ $w->last_heartbeat_at?->format('H:i:s') ?? '—' }}
                    </div>
                </div>
                @empty
                <p class="text-xs text-gray-500">No workers registered.</p>
                @endforelse
            </div>
        </div>

    </div>
</x-app-layout>
