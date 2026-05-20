<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Storage Pressure Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1">Reliability controls are operational safeguards only. No autonomous remediation, destructive replay, or hidden data deletion is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Notice:</strong> Storage pressure is advisory visibility only. No automatic retention changes, index deletion, or compaction policy mutation is executed.
        </div>

        <div class="grid grid-cols-5 gap-4 text-xs">
            @foreach(\App\Models\StoragePressureSnapshot::BACKENDS as $backend)
            @php $snap = $latestPerBackend->firstWhere('backend', $backend); @endphp
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400">{{ $backend }}</div>
                @if($snap)
                <div class="text-lg font-bold @if($snap->pressure_state === 'critical') text-red-300 @elseif($snap->pressure_state === 'warning') text-yellow-300 @else text-green-300 @endif">
                    {{ $snap->pressure_state }}
                </div>
                <div class="text-gray-500">{{ number_format($snap->retention_pressure_pct, 1) }}% retention</div>
                @else
                <div class="text-gray-500">no data</div>
                @endif
            </div>
            @endforeach
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Recent Snapshots ({{ $snapshots->count() }})</h3>
            @forelse($snapshots->take(50) as $s)
            <div class="flex items-center gap-2 py-1.5 border-b border-gray-800 last:border-0 text-xs">
                <span class="text-cyan-300 w-20">{{ $s->backend }}</span>
                <span class="px-1.5 py-0.5 rounded
                    @if($s->pressure_state === 'critical') bg-red-900/40 text-red-300
                    @elseif($s->pressure_state === 'warning') bg-yellow-900/40 text-yellow-300
                    @else bg-green-900/40 text-green-300 @endif">{{ $s->pressure_state }}</span>
                <span class="text-gray-400">{{ number_format($s->retention_pressure_pct, 1) }}%</span>
                @if($s->write_failure_rate > 0)<span class="text-red-400">wfr: {{ number_format($s->write_failure_rate * 100, 1) }}%</span>@endif
                <span class="ml-auto text-gray-500">{{ $s->created_at?->format('H:i:s') ?? '—' }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No storage pressure snapshots.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
