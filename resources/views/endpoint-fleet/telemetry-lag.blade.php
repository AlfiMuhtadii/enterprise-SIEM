<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Telemetry Lag Monitor</h2>
        <p class="text-xs text-amber-400/80 mt-1">Endpoint operations are advisory-only. No autonomous containment or enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Endpoint operations are advisory-only. No autonomous containment or enforcement is executed.
        </div>
        <div class="flex justify-end">
            <a href="{{ route('endpoint-fleet.dashboard') }}" class="text-xs text-cyan-400 hover:underline">← Fleet Dashboard</a>
        </div>

        <div class="grid grid-cols-3 gap-4">
            @foreach([
                ['Total Agents', $totalAgents, 'text-gray-300'],
                ['Stale / Offline', $staleAgents->count(), 'text-red-400'],
                ['With Lag Data', $lagSummary->count(), 'text-cyan-300'],
            ] as [$label, $val, $color])
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-1">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ $val }}</div>
            </div>
            @endforeach
        </div>

        <div class="glass-card p-0 overflow-hidden">
            <div class="flex justify-between items-center px-4 py-3 border-b border-gray-700">
                <span class="text-sm font-semibold text-cyan-200">Agents by Telemetry Lag (Highest First)</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-800/60 text-gray-400 uppercase tracking-wider">
                        <tr>
                            <th class="px-3 py-2 text-left">Agent ID</th>
                            <th class="px-3 py-2 text-left">Hostname</th>
                            <th class="px-3 py-2 text-center">Health</th>
                            <th class="px-3 py-2 text-right">Lag (seconds)</th>
                            <th class="px-3 py-2 text-left">Last Seen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                    @forelse($lagSummary as $row)
                        <tr class="hover:bg-gray-800/30">
                            <td class="px-3 py-2"><code class="text-gray-400">{{ Str::limit($row->agent_id, 20) }}</code></td>
                            <td class="px-3 py-2 text-cyan-400">{{ $row->hostname }}</td>
                            <td class="px-3 py-2 text-center">
                                <span class="px-1.5 py-0.5 rounded {{ $row->health_state === 'online' ? 'bg-green-900/40 text-green-300' : ($row->health_state === 'degraded' ? 'bg-yellow-900/40 text-yellow-300' : 'bg-red-900/40 text-red-300') }}">{{ $row->health_state }}</span>
                            </td>
                            <td class="px-3 py-2 text-right {{ $row->lag_seconds > 1800 ? 'text-red-400 font-bold' : ($row->lag_seconds > 300 ? 'text-yellow-400' : 'text-gray-300') }}">
                                {{ number_format($row->lag_seconds) }}s
                            </td>
                            <td class="px-3 py-2 text-gray-500">{{ \Carbon\Carbon::parse($row->last_seen_at)->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">No lag data available.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-xs text-gray-600 italic">High telemetry lag may indicate connectivity issues, agent failure, or network disruption. Investigation is advisory — no automated response is triggered.</p>
    </div>
</x-app-layout>
