<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Capacity Projection Console</h2>
        <p class="text-xs text-amber-400/80 mt-1">Capacity and performance governance are operational visibility controls only. No autonomous scaling, destructive retention purge, or hidden infrastructure mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Projection Method:</strong> Linear growth model. Confidence level: 70%. Actual growth may vary. No autonomous scaling triggered.
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Projection Runs ({{ $projections->count() }})</h3>
            @forelse($projections as $proj)
            <div class="py-2 border-b border-gray-800 last:border-0 text-xs">
                <div class="flex items-center gap-2">
                    <span class="text-cyan-300">{{ $proj->scope }}</span>
                    <span class="text-gray-400">{{ $proj->projection_days }}d horizon</span>
                    <span class="px-1.5 py-0.5 rounded
                        @if($proj->queue_pressure_forecast === 'critical') bg-red-900/40 text-red-300
                        @elseif($proj->queue_pressure_forecast === 'elevated') bg-yellow-900/40 text-yellow-300
                        @else bg-green-900/40 text-green-300 @endif">queue: {{ $proj->queue_pressure_forecast }}</span>
                    @if($proj->deterministic)<span class="text-blue-400">deterministic</span>@endif
                </div>
                <div class="text-gray-500 mt-0.5">
                    30d: {{ number_format($proj->projected_30d_bytes / 1024 / 1024, 1) }} MB
                    · 90d: {{ number_format($proj->projected_90d_bytes / 1024 / 1024, 1) }} MB
                    @if($proj->storage_exhaustion_forecast)
                    · exhaust: {{ $proj->storage_exhaustion_forecast }}
                    @endif
                </div>
            </div>
            @empty
            <p class="text-xs text-gray-500">No capacity projections.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
