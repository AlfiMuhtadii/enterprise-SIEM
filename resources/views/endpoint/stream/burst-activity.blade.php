<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Burst Activity Viewer</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Near-real-time advisory telemetry. Not kernel-level EDR.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-5">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Burst detection is advisory-only. No autonomous containment. All findings require analyst review.
        </div>

        <p class="text-xs text-gray-400">Showing event activity in the last 5-minute window across all agents.</p>

        @if($summary->isEmpty())
            <p class="text-sm text-gray-500">No activity in the current window.</p>
        @else
        <table class="w-full text-xs text-left text-gray-300">
            <thead class="text-gray-400 uppercase border-b border-gray-700">
                <tr>
                    <th class="py-2 pr-3">Agent</th><th class="py-2 pr-3">Event Type</th>
                    <th class="py-2">Count</th>
                </tr>
            </thead>
            <tbody>
                @foreach($summary as $row)
                <tr class="border-b border-gray-800 @if($row->event_count >= \App\Services\EndpointStreamingService::BURST_THRESHOLD) bg-red-900/10 @endif">
                    <td class="py-1.5 pr-3 font-mono text-xs text-cyan-400">{{ $row->agent_id }}</td>
                    <td class="py-1.5 pr-3">{{ str_replace('_', ' ', $row->event_type) }}</td>
                    <td class="py-1.5 font-bold @if($row->event_count >= \App\Services\EndpointStreamingService::BURST_THRESHOLD) text-red-400 @else text-gray-300 @endif">
                        {{ $row->event_count }}
                        @if($row->event_count >= \App\Services\EndpointStreamingService::BURST_THRESHOLD)
                            <span class="text-red-400/60 font-normal ml-1">BURST</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</x-app-layout>
