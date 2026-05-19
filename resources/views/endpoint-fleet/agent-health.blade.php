<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Agent Health Explorer</h2>
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
            <form method="GET" action="{{ route('endpoint-fleet.health') }}" class="flex flex-wrap gap-4 items-end">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Health State</label>
                    <select name="health_state" class="bg-gray-800 border border-gray-600 text-gray-200 text-xs rounded px-3 py-2">
                        <option value="">All</option>
                        @foreach($healthStates as $s)
                            <option value="{{ $s }}" @selected($s === $healthFilter)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Search</label>
                    <input type="text" name="search" class="bg-gray-800 border border-gray-600 text-gray-200 text-xs rounded px-3 py-2 w-48" value="{{ $search }}" placeholder="hostname, agent_id, IP">
                </div>
                <button type="submit" class="px-4 py-2 text-xs rounded bg-cyan-900/50 text-cyan-300 border border-cyan-700/40 hover:bg-cyan-900/70">Filter</button>
            </form>
        </div>

        <div class="glass-card p-0 overflow-hidden">
            <div class="flex justify-between items-center px-4 py-3 border-b border-gray-700">
                <span class="text-sm font-semibold text-cyan-200">Agents</span>
                <span class="text-xs text-gray-500">{{ $agents->count() }} results</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-800/60 text-gray-400 uppercase tracking-wider">
                        <tr>
                            <th class="px-3 py-2 text-left">Hostname</th>
                            <th class="px-3 py-2 text-left">Agent ID</th>
                            <th class="px-3 py-2 text-left">Platform</th>
                            <th class="px-3 py-2 text-left">Version</th>
                            <th class="px-3 py-2 text-center">Health</th>
                            <th class="px-3 py-2 text-left">IP</th>
                            <th class="px-3 py-2 text-left">Last Seen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                    @forelse($agents as $a)
                        <tr class="hover:bg-gray-800/30">
                            <td class="px-3 py-2">
                                <a href="{{ route('endpoint.agent.detail', $a->agent_id) }}" class="text-cyan-400 hover:underline">{{ $a->hostname }}</a>
                            </td>
                            <td class="px-3 py-2"><code class="text-gray-400">{{ Str::limit($a->agent_id, 20) }}</code></td>
                            <td class="px-3 py-2 text-gray-300">{{ $a->platform }}</td>
                            <td class="px-3 py-2 text-gray-400">{{ $a->agent_version }}</td>
                            <td class="px-3 py-2 text-center">
                                <span class="px-1.5 py-0.5 rounded text-xs
                                    {{ $a->health_state === 'online' ? 'bg-green-900/40 text-green-300' :
                                       ($a->health_state === 'degraded' ? 'bg-yellow-900/40 text-yellow-300' :
                                       ($a->health_state === 'stale' ? 'bg-orange-900/40 text-orange-300' : 'bg-red-900/40 text-red-300')) }}">
                                    {{ $a->health_state }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-gray-400">{{ $a->ip_address }}</td>
                            <td class="px-3 py-2 text-gray-500">{{ $a->last_seen_at ? \Carbon\Carbon::parse($a->last_seen_at)->diffForHumans() : 'never' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">No agents match your filters.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
