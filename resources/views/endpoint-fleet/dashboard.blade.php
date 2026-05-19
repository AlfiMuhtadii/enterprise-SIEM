<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Endpoint Fleet Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Endpoint operations are advisory-only. No autonomous containment or enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Endpoint operations are advisory-only. No autonomous containment or enforcement is executed.
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('endpoint-fleet.health') }}" class="px-3 py-1.5 text-xs rounded bg-cyan-900/30 text-cyan-300 border border-cyan-700/40 hover:bg-cyan-900/50">Agent Health</a>
            <a href="{{ route('endpoint-fleet.tamper') }}" class="px-3 py-1.5 text-xs rounded bg-red-900/30 text-red-300 border border-red-700/40 hover:bg-red-900/50">Tamper Visibility</a>
            <a href="{{ route('endpoint-fleet.spool') }}" class="px-3 py-1.5 text-xs rounded bg-yellow-900/30 text-yellow-300 border border-yellow-700/40 hover:bg-yellow-900/50">Spool Health</a>
            <a href="{{ route('endpoint-fleet.lag') }}" class="px-3 py-1.5 text-xs rounded bg-blue-900/30 text-blue-300 border border-blue-700/40 hover:bg-blue-900/50">Telemetry Lag</a>
            <a href="{{ route('endpoint-fleet.policies') }}" class="px-3 py-1.5 text-xs rounded bg-gray-800 text-gray-300 border border-gray-600 hover:bg-gray-700">Policies</a>
            <a href="{{ route('endpoint-fleet.drift') }}" class="px-3 py-1.5 text-xs rounded bg-orange-900/30 text-orange-300 border border-orange-700/40 hover:bg-orange-900/50">Policy Drift</a>
            <a href="{{ route('endpoint-fleet.enrollment') }}" class="px-3 py-1.5 text-xs rounded bg-gray-800 text-gray-300 border border-gray-600 hover:bg-gray-700">Enrollment Audit</a>
        </div>

        {{-- Fleet health stats --}}
        <div class="grid grid-cols-4 gap-4">
            @foreach([
                ['Total Agents', $stats['total'], 'text-gray-300'],
                ['Online', $stats['online'], 'text-green-400'],
                ['Degraded', $stats['degraded'], 'text-yellow-400'],
                ['Stale / Offline', $stats['stale'] + $stats['offline'], 'text-red-400'],
            ] as [$label, $val, $color])
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-1">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ number_format($val) }}</div>
            </div>
            @endforeach
        </div>

        <div class="grid grid-cols-4 gap-4">
            @foreach([
                ['Tamper Events (7d)', $stats['tamper_events_7d'], 'text-red-300'],
                ['Policy Drifts', $stats['policy_drifts'], 'text-orange-300'],
                ['Spool Warnings', $stats['spool_warnings'], 'text-yellow-300'],
                ['Dropped Events (24h)', $stats['total_dropped_events_24h'], 'text-purple-300'],
            ] as [$label, $val, $color])
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-1">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ number_format($val) }}</div>
            </div>
            @endforeach
        </div>

        <div class="grid grid-cols-2 gap-6">
            {{-- Stale/offline agents --}}
            <div class="glass-card p-4">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="text-sm font-semibold text-cyan-200">Stale / Offline Agents</h3>
                    <span class="text-xs text-amber-400/70 italic">Advisory Only</span>
                </div>
                @forelse($staleAgents as $a)
                    <div class="flex justify-between items-center py-1.5 border-b border-gray-800 text-xs">
                        <a href="{{ route('endpoint.agent.detail', $a->agent_id) }}" class="text-cyan-400 hover:underline">{{ $a->hostname }}</a>
                        <span class="px-1.5 py-0.5 rounded text-xs {{ $a->health_state === 'offline' ? 'bg-red-900/40 text-red-300' : 'bg-yellow-900/40 text-yellow-300' }}">{{ $a->health_state }}</span>
                        <span class="text-gray-500">{{ $a->last_seen_at ? \Carbon\Carbon::parse($a->last_seen_at)->diffForHumans() : 'never' }}</span>
                    </div>
                @empty
                    <p class="text-gray-500 text-xs">All agents healthy.</p>
                @endforelse
            </div>

            {{-- Recent tamper events --}}
            <div class="glass-card p-4">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="text-sm font-semibold text-cyan-200">Recent Tamper Events</h3>
                    <span class="text-xs text-amber-400/70 italic">Advisory Only</span>
                </div>
                @forelse($recentTamper as $t)
                    <div class="flex justify-between items-center py-1.5 border-b border-gray-800 text-xs">
                        <span class="text-gray-200">{{ str_replace('_', ' ', $t->tamper_type) }}</span>
                        <span class="px-1.5 py-0.5 rounded {{ $t->severity === 'critical' ? 'bg-red-900/40 text-red-300' : ($t->severity === 'high' ? 'bg-orange-900/40 text-orange-300' : 'bg-yellow-900/40 text-yellow-300') }}">{{ $t->severity }}</span>
                        <span class="text-gray-500">{{ \Carbon\Carbon::parse($t->detected_at)->diffForHumans() }}</span>
                    </div>
                @empty
                    <p class="text-gray-500 text-xs">No tamper events recently.</p>
                @endforelse
            </div>
        </div>

        <p class="text-xs text-gray-600 italic">Fleet management is advisory and observational only. All findings require analyst review. No automated containment is performed.</p>
    </div>
</x-app-layout>
