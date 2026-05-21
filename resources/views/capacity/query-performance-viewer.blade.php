<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Query Performance Viewer</h2>
        <p class="text-xs text-amber-400/80 mt-1">Capacity and performance governance are operational visibility controls only. No autonomous scaling, destructive retention purge, or hidden infrastructure mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="grid grid-cols-2 gap-4">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Latest by Backend</h3>
                @foreach($byBackend as $backend => $bsnaps)
                @php $latest = $bsnaps->sortByDesc('created_at')->first(); @endphp
                <div class="flex items-center justify-between py-1 text-xs border-b border-gray-800 last:border-0">
                    <span class="text-cyan-300 w-28">{{ $backend }}</span>
                    <span class="px-1.5 py-0.5 rounded
                        @if($latest->latency_state === 'critical') bg-red-900/40 text-red-300
                        @elseif($latest->latency_state === 'degraded') bg-orange-900/40 text-orange-300
                        @elseif($latest->latency_state === 'elevated') bg-yellow-900/40 text-yellow-300
                        @else bg-green-900/40 text-green-300 @endif">{{ $latest->latency_state }}</span>
                    <span class="text-gray-400">p95: {{ number_format($latest->p95_latency_ms) }}ms</span>
                    <span class="text-gray-500">slow: {{ $latest->slow_query_count }}</span>
                </div>
                @endforeach
                @if($snapshots->isEmpty())<p class="text-xs text-gray-500">No query snapshots.</p>@endif
            </div>

            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Degraded Queries ({{ $degraded->count() }})</h3>
                @forelse($degraded->take(20) as $s)
                <div class="py-1 border-b border-gray-800 last:border-0 text-xs">
                    <span class="text-orange-300">{{ $s->backend }}</span>
                    <span class="ml-2 text-gray-400">p95: {{ number_format($s->p95_latency_ms) }}ms</span>
                    <span class="ml-2 text-red-300">{{ $s->slow_query_count }} slow</span>
                </div>
                @empty
                <p class="text-xs text-gray-500">No degraded query snapshots.</p>
                @endforelse
            </div>
        </div>

    </div>
</x-app-layout>
