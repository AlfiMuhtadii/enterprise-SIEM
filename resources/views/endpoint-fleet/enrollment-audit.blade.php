<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Enrollment Audit View</h2>
        <p class="text-xs text-amber-400/80 mt-1">Endpoint operations are advisory-only. No autonomous containment or enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Endpoint operations are advisory-only. No autonomous containment or enforcement is executed.
        </div>
        <div class="flex justify-end">
            <a href="{{ route('endpoint-fleet.dashboard') }}" class="text-xs text-cyan-400 hover:underline">← Fleet Dashboard</a>
        </div>

        <div class="glass-card p-4">
            <form method="GET" action="{{ route('endpoint-fleet.enrollment') }}" class="flex flex-wrap gap-4 items-end">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Event Type</label>
                    <select name="event_type" class="bg-gray-800 border border-gray-600 text-gray-200 text-xs rounded px-3 py-2">
                        <option value="">All</option>
                        @foreach($eventTypes as $t)
                            <option value="{{ $t }}" @selected($t === $eventType)>{{ str_replace('_', ' ', $t) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Agent ID</label>
                    <input type="text" name="agent_id" class="bg-gray-800 border border-gray-600 text-gray-200 text-xs rounded px-3 py-2 w-48" value="{{ $agentId }}" placeholder="agent-xxx">
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Days</label>
                    <input type="number" name="days" class="bg-gray-800 border border-gray-600 text-gray-200 text-xs rounded px-3 py-2 w-20" value="{{ $days }}" min="1" max="30">
                </div>
                <button type="submit" class="px-4 py-2 text-xs rounded bg-cyan-900/50 text-cyan-300 border border-cyan-700/40 hover:bg-cyan-900/70">Filter</button>
            </form>
        </div>

        <div class="glass-card p-0 overflow-hidden">
            <div class="flex justify-between items-center px-4 py-3 border-b border-gray-700">
                <span class="text-sm font-semibold text-cyan-200">Enrollment Events</span>
                <span class="text-xs text-gray-500">{{ $events->count() }} results</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-800/60 text-gray-400 uppercase tracking-wider">
                        <tr>
                            <th class="px-3 py-2 text-left">Agent</th>
                            <th class="px-3 py-2 text-left">Event</th>
                            <th class="px-3 py-2 text-left">Platform</th>
                            <th class="px-3 py-2 text-left">Version</th>
                            <th class="px-3 py-2 text-left">IP</th>
                            <th class="px-3 py-2 text-center">Status</th>
                            <th class="px-3 py-2 text-left">Occurred</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                    @forelse($events as $e)
                        <tr class="hover:bg-gray-800/30">
                            <td class="px-3 py-2">
                                <span class="text-cyan-400">{{ $e->agent?->hostname ?? 'unknown' }}</span>
                                <div class="text-gray-600" style="font-size:0.65rem;">{{ $e->agent?->agent_id }}</div>
                            </td>
                            <td class="px-3 py-2 text-gray-300">{{ str_replace('_', ' ', $e->event_type) }}</td>
                            <td class="px-3 py-2 text-gray-400">{{ $e->platform }}</td>
                            <td class="px-3 py-2 text-gray-400">{{ $e->agent_version }}</td>
                            <td class="px-3 py-2 text-gray-400">{{ $e->ip_address }}</td>
                            <td class="px-3 py-2 text-center">
                                <span class="px-1.5 py-0.5 rounded {{ $e->successful ? 'bg-green-900/40 text-green-300' : 'bg-red-900/40 text-red-300' }}">
                                    {{ $e->successful ? 'ok' : 'failed' }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-gray-500">{{ \Carbon\Carbon::parse($e->occurred_at)->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">No enrollment events found.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
