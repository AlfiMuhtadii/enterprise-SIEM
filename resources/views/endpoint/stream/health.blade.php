<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Stream Health Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Near-real-time advisory telemetry. Not kernel-level EDR.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-5">
        @if($health->isEmpty())
            <p class="text-sm text-gray-500">No stream health data. Agents must submit stream batches to populate.</p>
        @else
        <div class="overflow-x-auto">
        <table class="w-full text-xs text-left text-gray-300">
            <thead class="text-gray-400 uppercase border-b border-gray-700">
                <tr>
                    <th class="py-2 pr-3">Agent</th><th class="py-2 pr-3">Health State</th>
                    <th class="py-2 pr-3">Last Seq</th><th class="py-2 pr-3">Lag (s)</th>
                    <th class="py-2 pr-3">Dropped</th><th class="py-2 pr-3">Batches</th><th class="py-2">Checked</th>
                </tr>
            </thead>
            <tbody>
                @foreach($health as $h)
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 pr-3 font-mono text-xs">
                        <a href="{{ route('endpoint.stream.timeline', $h->agent_id) }}" class="text-cyan-400 hover:underline">{{ $h->agent_id }}</a>
                    </td>
                    <td class="py-1.5 pr-3">
                        <span class="px-1.5 py-0.5 rounded text-xs
                            @if($h->stream_health_state === 'healthy') bg-green-900/40 text-green-300
                            @elseif($h->stream_health_state === 'lagging') bg-amber-900/40 text-amber-300
                            @elseif($h->stream_health_state === 'degraded') bg-red-900/40 text-red-300
                            @else bg-gray-700 text-gray-400 @endif">
                            {{ $h->stream_health_state }}
                        </span>
                    </td>
                    <td class="py-1.5 pr-3 font-mono">{{ number_format($h->last_sequence_seen) }}</td>
                    <td class="py-1.5 pr-3">{{ $h->stream_lag_seconds }}</td>
                    <td class="py-1.5 pr-3 @if($h->dropped_event_count > 0) text-red-400 @endif">{{ $h->dropped_event_count }}</td>
                    <td class="py-1.5 pr-3">{{ $h->batch_count }}</td>
                    <td class="py-1.5 text-gray-500">{{ \Carbon\Carbon::parse($h->created_at)->format('H:i:s') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        @endif

        <div class="text-xs text-gray-500 pt-2">
            Health states: healthy = lag &lt; 30s, lagging = 30-120s, degraded = &gt;120s. Append-only snapshots.
        </div>
    </div>
</x-app-layout>
