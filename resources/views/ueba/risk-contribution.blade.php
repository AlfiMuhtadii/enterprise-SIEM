<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">UEBA Risk Contribution</h2>
        <p class="text-xs text-amber-400/80 mt-1">Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('entity.risk-dashboard') }}" class="text-xs text-cyan-400 hover:underline">← Entity Risk Dashboard</a>
            <a href="{{ route('ueba.dashboard') }}" class="text-xs text-cyan-400 hover:underline">UEBA Dashboard →</a>
        </div>

        {{-- Search form --}}
        <div class="glass-card p-4">
            <form method="GET" action="{{ route('ueba.risk-contribution') }}" class="flex flex-wrap gap-4 items-end">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Entity Key</label>
                    <input type="text" name="entity_key" class="bg-gray-800 border border-gray-600 text-gray-200 text-xs rounded px-3 py-2 w-72" value="{{ $entityKey }}" placeholder="e.g. alice@example.com">
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Entity Type</label>
                    <select name="entity_type" class="bg-gray-800 border border-gray-600 text-gray-200 text-xs rounded px-3 py-2">
                        @foreach($entityTypes as $t)
                            <option value="{{ $t }}" @selected($t === $entityType)>{{ ucfirst($t) }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="px-4 py-2 text-xs rounded bg-cyan-900/50 text-cyan-300 border border-cyan-700/40 hover:bg-cyan-900/70">Load</button>
            </form>
        </div>

        @if($entityKey && $contributions->isNotEmpty())
            {{-- Risk factor explanation --}}
            <div class="glass-card p-4">
                <h3 class="text-sm font-semibold text-cyan-200 mb-3">UEBA Risk Factor Weights</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
                    @foreach([
                        ['baseline_anomaly_factor', '2.0', 'Baseline Anomaly'],
                        ['peer_deviation_factor', '1.5', 'Peer Deviation'],
                        ['abnormal_data_volume_factor', '2.0', 'Abnormal Data Volume'],
                        ['unusual_activity_time_factor', '1.5', 'Unusual Activity Time'],
                    ] as [$factor, $weight, $label])
                    <div class="border border-gray-700 rounded p-3">
                        <div class="font-semibold text-gray-200">{{ $label }}</div>
                        <div class="text-gray-400 mt-1">Weight: <strong class="text-cyan-300">{{ $weight }}</strong></div>
                        <div class="text-gray-600 mt-1"><code>{{ $factor }}</code></div>
                    </div>
                    @endforeach
                </div>
                <p class="text-xs text-gray-500 mt-3">UEBA factors are advisory amplifiers only. Risk score is capped at 10.0. All factors are marked <code>advisory_only=true</code>.</p>
            </div>

            {{-- Contribution breakdown --}}
            <div class="glass-card p-0 overflow-hidden">
                <div class="flex justify-between items-center px-4 py-3 border-b border-gray-700">
                    <span class="text-sm font-semibold text-cyan-200">Contributing Anomalies (High Confidence, Last 7d)</span>
                    <span class="text-xs text-yellow-400">{{ $contributions->count() }} contributing</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-gray-800/60 text-gray-400 uppercase tracking-wider">
                            <tr>
                                <th class="px-3 py-2 text-left">Anomaly Type</th>
                                <th class="px-3 py-2 text-left">Dimension</th>
                                <th class="px-3 py-2 text-left">Risk Factor</th>
                                <th class="px-3 py-2 text-right">Observed</th>
                                <th class="px-3 py-2 text-right">Baseline</th>
                                <th class="px-3 py-2 text-right">Deviation</th>
                                <th class="px-3 py-2 text-center">Confidence</th>
                                <th class="px-3 py-2 text-left">Scored</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800">
                        @foreach($contributions as $c)
                            <tr class="hover:bg-gray-800/30">
                                <td class="px-3 py-2 text-gray-300">{{ str_replace('_', ' ', $c['anomaly_type']) }}</td>
                                <td class="px-3 py-2"><code class="text-gray-400">{{ $c['dimension'] }}</code></td>
                                <td class="px-3 py-2"><code class="text-cyan-400">{{ $c['risk_factor'] }}</code></td>
                                <td class="px-3 py-2 text-right text-gray-200">{{ round($c['observed_value'], 3) }}</td>
                                <td class="px-3 py-2 text-right text-gray-400">{{ round($c['baseline_value'], 3) }}</td>
                                <td class="px-3 py-2 text-right text-yellow-400">{{ round($c['deviation'], 3) }}</td>
                                <td class="px-3 py-2 text-center">
                                    <span class="px-1.5 py-0.5 rounded {{ $c['confidence'] >= 0.75 ? 'bg-red-900/40 text-red-300' : 'bg-yellow-900/40 text-yellow-300' }}">
                                        {{ round($c['confidence'] * 100) }}%
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-gray-500">{{ \Carbon\Carbon::parse($c['scored_at'])->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

        @elseif($entityKey)
            <div class="glass-card p-4 text-gray-500 text-sm">No high-confidence UEBA anomalies contributing to risk for <strong class="text-gray-300">{{ $entityKey }}</strong> in the last 7 days.</div>
        @endif

        <p class="text-xs text-gray-600 italic">All UEBA risk contributions are advisory-only. No autonomous account actions, host isolation, or process termination are performed. Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.</p>
    </div>
</x-app-layout>
