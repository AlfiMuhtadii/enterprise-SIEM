<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Consumer Lag Monitor</h2>
        <p class="text-xs text-amber-400/80 mt-1">Reliability controls are operational safeguards only. No autonomous remediation, destructive replay, or hidden data deletion is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        @if($critical->count() > 0)
        <div class="rounded border border-red-500/40 bg-red-900/10 px-4 py-3 text-sm text-red-300">
            <strong>⚠ Critical Lag Detected:</strong> {{ $critical->count() }} topic(s) at critical lag levels.
        </div>
        @endif

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Consumer Groups ({{ $byGroup->count() }})</h3>
            @foreach($byGroup as $group => $groupSnaps)
            <div class="mb-4">
                <div class="text-xs font-medium text-cyan-300 mb-1">{{ $group }}</div>
                @foreach($groupSnaps->take(5) as $snap)
                <div class="flex items-center gap-2 py-1 border-b border-gray-800 last:border-0 text-xs">
                    <span class="text-gray-400 w-40">{{ $snap->topic }}</span>
                    <div class="flex-1 bg-gray-800 rounded h-1.5">
                        <div class="h-1.5 rounded @if($snap->pressure_state === 'critical') bg-red-500 @elseif($snap->pressure_state === 'warning') bg-yellow-500 @else bg-green-500 @endif"
                             style="width: {{ min(100, $snap->lag_count / 100) }}%"></div>
                    </div>
                    <span class="w-16 text-right @if($snap->pressure_state === 'critical') text-red-300 @elseif($snap->pressure_state === 'warning') text-yellow-300 @else text-green-300 @endif">
                        {{ number_format($snap->lag_count) }}
                    </span>
                    <span class="text-gray-500">{{ $snap->trend }}</span>
                    @if($snap->dlq_growing)<span class="text-red-400">DLQ↑</span>@endif
                </div>
                @endforeach
            </div>
            @endforeach
            @if($snapshots->isEmpty())
            <p class="text-xs text-gray-500">No lag snapshots recorded.</p>
            @endif
        </div>

    </div>
</x-app-layout>
