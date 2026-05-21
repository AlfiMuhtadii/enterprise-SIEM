<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Partition Pressure Monitor</h2>
        <p class="text-xs text-amber-400/80 mt-1">Capacity and performance governance are operational visibility controls only. No autonomous scaling, destructive retention purge, or hidden infrastructure mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="grid grid-cols-4 gap-4 text-xs">
            @foreach(\App\Models\PartitionPressureSnapshot::HEALTH_STATES as $state)
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400">{{ $state }}</div>
                <div class="text-2xl font-bold
                    @if($state === 'stalled') text-red-300
                    @elseif($state === 'pressure') text-yellow-300
                    @elseif($state === 'lagging') text-orange-300
                    @else text-green-300 @endif">{{ $byHealth->get($state)?->count() ?? 0 }}</div>
            </div>
            @endforeach
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Partitions ({{ $partitions->count() }})</h3>
            @forelse($partitions as $p)
            <div class="py-1.5 border-b border-gray-800 last:border-0 text-xs flex items-center gap-2">
                <span class="text-cyan-300 w-32 truncate">{{ $p->topic }}</span>
                <span class="text-gray-400 w-6">P{{ $p->partition_id }}</span>
                <span class="px-1.5 py-0.5 rounded
                    @if($p->health_state === 'stalled') bg-red-900/40 text-red-300
                    @elseif($p->health_state === 'pressure') bg-yellow-900/40 text-yellow-300
                    @elseif($p->health_state === 'lagging') bg-orange-900/40 text-orange-300
                    @else bg-green-900/40 text-green-300 @endif">{{ $p->health_state }}</span>
                <span class="text-gray-400">lag: {{ number_format($p->offset_lag) }}</span>
                <span class="text-gray-500">{{ number_format($p->utilization_pct, 1) }}%</span>
                @if($p->is_leader)<span class="text-blue-400">leader</span>@endif
            </div>
            @empty
            <p class="text-xs text-gray-500">No partition snapshots.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
