<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Streaming Telemetry Monitor</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Near-real-time advisory telemetry. Not kernel-level EDR.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-6xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Streaming telemetry is advisory-only. No kernel instrumentation. Collection sources: /proc, filesystem watchers, process inspection.
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-1">Agents Streaming</div>
                <div class="text-2xl font-bold text-cyan-300">{{ $health->count() }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-1">Healthy Streams</div>
                <div class="text-2xl font-bold text-green-400">{{ $health->where('stream_health_state', 'healthy')->count() }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-1">Recent Checkpoints</div>
                <div class="text-2xl font-bold text-blue-300">{{ $checkpoints->count() }}</div>
            </div>
        </div>

        <h3 class="text-sm font-semibold text-cyan-200">Recent Stream Checkpoints</h3>
        @if($checkpoints->isEmpty())
            <p class="text-sm text-gray-500">No checkpoints recorded yet.</p>
        @else
        <div class="overflow-x-auto">
        <table class="w-full text-xs text-left text-gray-300">
            <thead class="text-gray-400 uppercase border-b border-gray-700">
                <tr>
                    <th class="py-2 pr-3">Checkpoint ID</th><th class="py-2 pr-3">Agent</th>
                    <th class="py-2 pr-3">Sequence</th><th class="py-2 pr-3">Events</th>
                    <th class="py-2 pr-3">Lag (s)</th><th class="py-2">Created</th>
                </tr>
            </thead>
            <tbody>
                @foreach($checkpoints as $cp)
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 pr-3 font-mono text-cyan-400 text-xs">{{ $cp->checkpoint_id }}</td>
                    <td class="py-1.5 pr-3 font-mono text-xs">{{ $cp->agent_id }}</td>
                    <td class="py-1.5 pr-3">{{ number_format($cp->sequence_id) }}</td>
                    <td class="py-1.5 pr-3">{{ $cp->event_count }}</td>
                    <td class="py-1.5 pr-3">{{ $cp->stream_lag_seconds }}</td>
                    <td class="py-1.5 text-gray-500">{{ \Carbon\Carbon::parse($cp->created_at)->format('H:i:s') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        @endif
    </div>
</x-app-layout>
