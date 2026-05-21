<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Storage Capacity Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1">Capacity and performance governance are operational visibility controls only. No autonomous scaling, destructive retention purge, or hidden infrastructure mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Notice:</strong> Storage capacity projections are advisory visibility only. No automatic purge or shard deletion is executed.
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 text-xs">
            @foreach(\App\Models\StorageCapacitySnapshot::BACKENDS as $backend)
            @php $snap = $latest->firstWhere('backend', $backend); @endphp
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400">{{ $backend }}</div>
                @if($snap)
                <div class="text-lg font-bold @if($snap->capacity_state === 'critical') text-red-300 @elseif($snap->capacity_state === 'pressure') text-yellow-300 @elseif($snap->capacity_state === 'growing') text-orange-300 @else text-green-300 @endif">
                    {{ $snap->capacity_state }}
                </div>
                <div class="text-gray-500">{{ $snap->estimated_exhaustion_date ?? 'no forecast' }}</div>
                @else
                <div class="text-gray-500">no data</div>
                @endif
            </div>
            @endforeach
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Recent Snapshots ({{ $snapshots->count() }})</h3>
            @forelse($snapshots->take(30) as $s)
            <div class="flex items-center gap-2 py-1.5 border-b border-gray-800 last:border-0 text-xs">
                <span class="text-cyan-300 w-20">{{ $s->backend }}</span>
                <span class="px-1.5 py-0.5 rounded
                    @if($s->capacity_state === 'critical') bg-red-900/40 text-red-300
                    @elseif($s->capacity_state === 'pressure') bg-yellow-900/40 text-yellow-300
                    @elseif($s->capacity_state === 'growing') bg-orange-900/40 text-orange-300
                    @else bg-green-900/40 text-green-300 @endif">{{ $s->capacity_state }}</span>
                <span class="text-gray-400">{{ number_format($s->current_size_bytes / 1024 / 1024, 1) }} MB</span>
                <span class="text-gray-500">{{ number_format($s->growth_rate_bytes_per_day / 1024, 1) }} KB/day</span>
                <span class="ml-auto text-gray-500">exhaust: {{ $s->estimated_exhaustion_date ?? '—' }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No storage capacity snapshots.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
