<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Endpoint Policy Drift View</h2>
        <p class="text-xs text-amber-400/80 mt-1">Endpoint operations are advisory-only. No autonomous containment or enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Endpoint operations are advisory-only. No autonomous containment or enforcement is executed.
        </div>
        <div class="flex justify-end">
            <a href="{{ route('endpoint-fleet.dashboard') }}" class="text-xs text-cyan-400 hover:underline">← Fleet Dashboard</a>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach([
                ['Total Agents', $totalAgents, 'text-gray-300'],
                ['Policy Drifts', $driftAgents->count(), 'text-orange-400'],
                ['In Compliance', $totalAgents - $driftAgents->count(), 'text-green-400'],
            ] as [$label, $val, $color])
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-1">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ $val }}</div>
            </div>
            @endforeach
        </div>

        @if($driftAgents->isEmpty())
            <div class="glass-card p-8 text-center text-green-400 text-sm">All agents are running their assigned fleet policy.</div>
        @else
            <div class="glass-card p-0 overflow-hidden">
                <div class="flex justify-between items-center px-4 py-3 border-b border-gray-700">
                    <span class="text-sm font-semibold text-cyan-200">Agents with Policy Drift</span>
                    <span class="text-xs text-orange-400">{{ $driftAgents->count() }} drifted</span>
                </div>
                @foreach($driftAgents as $d)
                    <div class="border-b border-gray-800 px-4 py-3 text-xs">
                        <div class="flex justify-between items-start">
                            <div>
                                <strong class="text-gray-200">{{ $d['agent']->hostname }}</strong>
                                <span class="ml-2 text-gray-500">{{ $d['agent']->agent_id }}</span>
                            </div>
                            <span class="px-1.5 py-0.5 rounded bg-orange-900/40 text-orange-300">{{ $d['severity'] }}</span>
                        </div>
                        <div class="mt-2 grid grid-cols-2 gap-4 text-gray-400">
                            <div>
                                <span class="text-gray-500">Assigned hash: </span>
                                <code class="text-gray-300">{{ Str::limit($d['drift']['assigned_config_hash'] ?? '', 16) }}…</code>
                            </div>
                            <div>
                                <span class="text-gray-500">Reported hash: </span>
                                <code class="text-red-300">{{ Str::limit($d['drift']['reported_config_hash'] ?? '', 16) }}…</code>
                            </div>
                            <div>
                                <span class="text-gray-500">Assigned at: </span>{{ $d['drift']['assigned_at'] ?? '—' }}
                            </div>
                            <div>
                                <span class="text-gray-500">Last heartbeat: </span>{{ $d['drift']['last_heartbeat_at'] ?? '—' }}
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <p class="text-xs text-gray-600 italic">Policy drift is detected by comparing the config hash reported in the agent heartbeat to the hash of the currently assigned fleet policy. Advisory-only — no automated push.</p>
    </div>
</x-app-layout>
