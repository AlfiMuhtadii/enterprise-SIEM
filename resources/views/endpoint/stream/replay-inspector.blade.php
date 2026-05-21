<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Stream Replay Inspector</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Near-real-time advisory telemetry. Not kernel-level EDR.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-5">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Replay window is bounded to {{ \App\Services\EndpointStreamingService::REPLAY_WINDOW_SECONDS }}s.
            Replay is read-only. No source data mutations.
        </div>

        <form method="GET" class="flex gap-3 items-center">
            <select name="agent_id" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5">
                <option value="">Select Agent</option>
                @foreach($agents as $a)
                    <option value="{{ $a->agent_id }}" @selected($a->agent_id === $agentId)>{{ $a->hostname }}</option>
                @endforeach
            </select>
            <input type="number" name="from_sequence" value="{{ $fromSequence }}" min="0"
                   class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-40"
                   placeholder="From sequence">
            <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm">Inspect</button>
        </form>

        @if(!empty($gaps))
        <div class="text-xs text-amber-300 space-y-0.5">
            <div class="font-medium">Sequence gaps in replay window:</div>
            @foreach($gaps as $gap)
                <div class="font-mono">{{ $gap['from'] }} → {{ $gap['to'] }} ({{ $gap['missing_count'] }} missing)</div>
            @endforeach
        </div>
        @endif

        @if($agentId && $fromSequence > 0)
            @if($events->isEmpty())
                <p class="text-sm text-gray-500">No events found from sequence {{ $fromSequence }} within the replay window.</p>
            @else
            <div class="text-xs text-gray-400 mb-2">Showing {{ $events->count() }} events from sequence {{ $fromSequence }}</div>
            <div class="overflow-x-auto">
            <table class="w-full text-xs text-left text-gray-300">
                <thead class="text-gray-400 uppercase border-b border-gray-700">
                    <tr>
                        <th class="py-2 pr-3">Seq</th><th class="py-2 pr-3">Type</th>
                        <th class="py-2 pr-3">Process</th><th class="py-2 pr-3">Event ID</th><th class="py-2">Occurred</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($events as $ev)
                    <tr class="border-b border-gray-800">
                        <td class="py-1.5 pr-3 font-mono text-cyan-400">{{ $ev->sequence_id }}</td>
                        <td class="py-1.5 pr-3">{{ str_replace('_', ' ', $ev->event_type) }}</td>
                        <td class="py-1.5 pr-3 font-mono">{{ $ev->process_name }}</td>
                        <td class="py-1.5 pr-3 font-mono text-xs text-gray-500">{{ $ev->event_id }}</td>
                        <td class="py-1.5 text-gray-500">{{ $ev->occurred_at?->format('H:i:s') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            @endif
        @endif
    </div>
</x-app-layout>
