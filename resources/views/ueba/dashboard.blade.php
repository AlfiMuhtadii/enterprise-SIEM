<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">UEBA Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        {{-- Advisory disclaimer --}}
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.
        </div>

        {{-- Navigation --}}
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('ueba.anomaly-explorer') }}" class="px-3 py-1.5 text-xs rounded bg-yellow-900/30 text-yellow-300 border border-yellow-700/40 hover:bg-yellow-900/50">Anomaly Explorer</a>
            <a href="{{ route('ueba.peer-groups') }}" class="px-3 py-1.5 text-xs rounded bg-blue-900/30 text-blue-300 border border-blue-700/40 hover:bg-blue-900/50">Peer Groups</a>
            <a href="{{ route('ueba.drift-monitor') }}" class="px-3 py-1.5 text-xs rounded bg-gray-800 text-gray-300 border border-gray-600 hover:bg-gray-700">Drift Monitor</a>
            <a href="{{ route('ueba.baseline-profile') }}" class="px-3 py-1.5 text-xs rounded bg-gray-800 text-gray-300 border border-gray-600 hover:bg-gray-700">Baseline Profile</a>
        </div>

        {{-- Summary stats --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach([
                ['Behavioral Baselines', $stats['total_baselines'], 'text-cyan-300'],
                ['Anomalies (7d)', $stats['total_anomalies'], 'text-yellow-400'],
                ['High Confidence (≥75%)', $stats['high_confidence'], 'text-red-400'],
                ['Peer Groups', $stats['peer_groups'], 'text-green-400'],
            ] as [$label, $val, $color])
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-1">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ number_format($val) }}</div>
            </div>
            @endforeach
        </div>

        <div class="grid grid-cols-2 gap-6">
            {{-- Top anomalous users --}}
            <div class="glass-card p-4">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="text-sm font-semibold text-cyan-200">Top Anomalous Users</h3>
                    <span class="text-xs text-amber-400/70 italic">Advisory Only</span>
                </div>
                @forelse($topUsers as $u)
                    <div class="flex justify-between items-center py-1.5 border-b border-gray-800 text-xs">
                        <a href="{{ route('ueba.entity-history', ['entity_key' => $u->entity_key, 'entity_type' => 'user']) }}" class="text-cyan-400 hover:underline truncate max-w-xs">{{ $u->entity_key }}</a>
                        <span class="ml-2 px-1.5 py-0.5 rounded bg-yellow-900/40 text-yellow-300 text-xs">{{ $u->anomaly_count }}</span>
                        <span class="text-gray-500 ml-2">{{ round($u->max_confidence * 100) }}%</span>
                    </div>
                @empty
                    <p class="text-gray-500 text-xs">No anomalous users in the last 7 days.</p>
                @endforelse
            </div>

            {{-- Top anomalous hosts --}}
            <div class="glass-card p-4">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="text-sm font-semibold text-cyan-200">Top Anomalous Hosts</h3>
                    <span class="text-xs text-amber-400/70 italic">Advisory Only</span>
                </div>
                @forelse($topHosts as $h)
                    <div class="flex justify-between items-center py-1.5 border-b border-gray-800 text-xs">
                        <a href="{{ route('ueba.entity-history', ['entity_key' => $h->entity_key, 'entity_type' => 'host']) }}" class="text-cyan-400 hover:underline truncate max-w-xs">{{ $h->entity_key }}</a>
                        <span class="ml-2 px-1.5 py-0.5 rounded bg-yellow-900/40 text-yellow-300 text-xs">{{ $h->anomaly_count }}</span>
                        <span class="text-gray-500 ml-2">{{ round($h->max_confidence * 100) }}%</span>
                    </div>
                @empty
                    <p class="text-gray-500 text-xs">No anomalous hosts in the last 7 days.</p>
                @endforelse
            </div>
        </div>

        {{-- Active anomaly types --}}
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Active Anomaly Types (Last 7 Days)</h3>
            <div class="flex flex-wrap gap-2">
                @forelse($stats['anomaly_types'] as $type)
                    <a href="{{ route('ueba.anomaly-explorer', ['anomaly_type' => $type]) }}"
                       class="px-2 py-1 text-xs rounded bg-gray-700 text-gray-300 border border-gray-600 hover:bg-gray-600">
                        {{ str_replace('_', ' ', $type) }}
                    </a>
                @empty
                    <span class="text-gray-500 text-xs">No anomalies detected in the last 7 days.</span>
                @endforelse
            </div>
        </div>

        <p class="text-xs text-gray-600 italic">All UEBA baselines and anomaly scores are statistical and explainable. No hidden model decisions. Results are advisory investigation aids only.</p>
    </div>
</x-app-layout>
