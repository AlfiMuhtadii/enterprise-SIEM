<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Cardinality Pressure Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1">Capacity and performance governance are operational visibility controls only. No autonomous scaling, destructive retention purge, or hidden infrastructure mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="grid grid-cols-2 gap-4">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">By Pressure Type</h3>
                @foreach($byType as $type => $typeReports)
                <div class="flex justify-between py-1 text-xs border-b border-gray-800 last:border-0">
                    <span class="text-purple-300">{{ $type }}</span>
                    <span class="text-gray-300">{{ $typeReports->count() }}</span>
                </div>
                @endforeach
                @if($reports->isEmpty())<p class="text-xs text-gray-500">No reports.</p>@endif
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Recent Reports ({{ $reports->count() }})</h3>
                <div class="space-y-1 max-h-64 overflow-y-auto">
                    @forelse($reports->take(30) as $r)
                    <div class="py-1 border-b border-gray-800 last:border-0 text-xs flex items-center gap-2">
                        <span class="text-gray-400 w-32 truncate">{{ $r->field_name }}</span>
                        <span class="px-1.5 py-0.5 rounded
                            @if($r->severity === 'critical') bg-red-900/40 text-red-300
                            @elseif($r->severity === 'warning') bg-yellow-900/40 text-yellow-300
                            @else bg-gray-700 text-gray-400 @endif">{{ $r->severity }}</span>
                        <span class="text-gray-500">{{ number_format($r->cardinality_count) }}</span>
                        @if(!$r->bounded)<span class="text-red-400">UNBOUNDED</span>@endif
                    </div>
                    @empty
                    <p class="text-xs text-gray-500">No cardinality reports.</p>
                    @endforelse
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
