<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Live Endpoint Activity Feed</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Near-real-time advisory telemetry. Not kernel-level EDR.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-5">
        <form method="GET" class="flex gap-3 items-center flex-wrap">
            <select name="agent_id" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5">
                <option value="">All Agents</option>
                @foreach($agents as $a)
                    <option value="{{ $a->agent_id }}" @selected($a->agent_id === $agentId)>{{ $a->hostname }}</option>
                @endforeach
            </select>
            <select name="event_type" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5">
                <option value="">All Event Types</option>
                @foreach(\App\Models\EndpointStreamEvent::EVENT_TYPES as $et)
                    <option value="{{ $et }}" @selected($et === $eventType)>{{ str_replace('_', ' ', $et) }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm">Refresh</button>
        </form>

        @if($events->isEmpty())
            <p class="text-sm text-gray-500">No stream events found. Events are ingested via agent stream-batch API.</p>
        @else
        <table class="w-full text-xs text-left text-gray-300">
            <thead class="text-gray-400 uppercase border-b border-gray-700">
                <tr>
                    <th class="py-2 pr-3">Sequence</th><th class="py-2 pr-3">Event Type</th>
                    <th class="py-2 pr-3">Agent</th><th class="py-2 pr-3">Process</th>
                    <th class="py-2 pr-3">Dest</th><th class="py-2">Occurred</th>
                </tr>
            </thead>
            <tbody>
                @foreach($events as $ev)
                <tr class="border-b border-gray-800 hover:bg-gray-800/20">
                    <td class="py-1.5 pr-3 font-mono text-cyan-400">{{ $ev->sequence_id }}</td>
                    <td class="py-1.5 pr-3">
                        <span class="px-1.5 py-0.5 rounded text-xs
                            @if(str_contains($ev->event_type, 'shell')) bg-red-900/40 text-red-300
                            @elseif(str_contains($ev->event_type, 'persistence')) bg-amber-900/40 text-amber-300
                            @elseif(str_contains($ev->event_type, 'connection')) bg-blue-900/40 text-blue-300
                            @else bg-gray-700 text-gray-300 @endif">
                            {{ str_replace('_', ' ', $ev->event_type) }}
                        </span>
                    </td>
                    <td class="py-1.5 pr-3 font-mono text-xs text-gray-400">{{ $ev->agent_id }}</td>
                    <td class="py-1.5 pr-3 font-mono">{{ $ev->process_name }}</td>
                    <td class="py-1.5 pr-3 text-gray-400">{{ $ev->connection_dest }}{{ $ev->connection_port ? ':'.$ev->connection_port : '' }}</td>
                    <td class="py-1.5 text-gray-500">{{ $ev->occurred_at?->format('H:i:s') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</x-app-layout>
