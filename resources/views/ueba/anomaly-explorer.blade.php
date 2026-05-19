<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Anomaly Score Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1">Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.
        </div>

        <div class="flex justify-end">
            <a href="{{ route('ueba.dashboard') }}" class="text-xs text-cyan-400 hover:underline">← UEBA Dashboard</a>
        </div>

        {{-- Filters --}}
        <div class="glass-card p-4">
            <form method="GET" action="{{ route('ueba.anomaly-explorer') }}" class="flex flex-wrap gap-4 items-end">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Anomaly Type</label>
                    <select name="anomaly_type" class="bg-gray-800 border border-gray-600 text-gray-200 text-xs rounded px-3 py-2">
                        <option value="">All types</option>
                        @foreach($anomalyTypes as $t)
                            <option value="{{ $t }}" @selected($t === $anomalyType)>{{ str_replace('_', ' ', $t) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Entity Type</label>
                    <select name="entity_type" class="bg-gray-800 border border-gray-600 text-gray-200 text-xs rounded px-3 py-2">
                        <option value="">All</option>
                        @foreach($entityTypes as $t)
                            <option value="{{ $t }}" @selected($t === $entityType)>{{ ucfirst($t) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Min Confidence</label>
                    <input type="number" name="min_confidence" class="bg-gray-800 border border-gray-600 text-gray-200 text-xs rounded px-3 py-2 w-24" value="{{ $minConf }}" min="0" max="1" step="0.05">
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Days</label>
                    <input type="number" name="days" class="bg-gray-800 border border-gray-600 text-gray-200 text-xs rounded px-3 py-2 w-20" value="{{ $days }}" min="1" max="30">
                </div>
                <button type="submit" class="px-4 py-2 text-xs rounded bg-cyan-900/50 text-cyan-300 border border-cyan-700/40 hover:bg-cyan-900/70">Filter</button>
            </form>
        </div>

        {{-- Results --}}
        <div class="glass-card p-0 overflow-hidden">
            <div class="flex justify-between items-center px-4 py-3 border-b border-gray-700">
                <span class="text-sm font-semibold text-cyan-200">Anomaly Scores</span>
                <span class="text-xs text-gray-500">{{ $scores->count() }} results</span>
            </div>
            @if($scores->isEmpty())
                <div class="text-center text-gray-500 text-sm py-8">No anomaly scores match your filters.</div>
            @else
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-800/60 text-gray-400 uppercase tracking-wider">
                        <tr>
                            <th class="px-3 py-2 text-left">Entity</th>
                            <th class="px-3 py-2 text-left">Anomaly Type</th>
                            <th class="px-3 py-2 text-left">Dimension</th>
                            <th class="px-3 py-2 text-right">Observed</th>
                            <th class="px-3 py-2 text-right">Baseline</th>
                            <th class="px-3 py-2 text-right">Z-Score</th>
                            <th class="px-3 py-2 text-left">Method</th>
                            <th class="px-3 py-2 text-center">Confidence</th>
                            <th class="px-3 py-2 text-left">Scored</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                    @foreach($scores as $s)
                        <tr class="hover:bg-gray-800/30">
                            <td class="px-3 py-2">
                                <a href="{{ route('ueba.entity-history', ['entity_key' => $s->entity_key, 'entity_type' => $s->entity_type]) }}" class="text-cyan-400 hover:underline">{{ Str::limit($s->entity_key, 28) }}</a>
                                <span class="ml-1 px-1 py-0.5 rounded bg-gray-700 text-gray-400 text-xs">{{ $s->entity_type }}</span>
                            </td>
                            <td class="px-3 py-2 text-gray-300">{{ str_replace('_', ' ', $s->anomaly_type) }}</td>
                            <td class="px-3 py-2"><code class="text-gray-400">{{ $s->dimension }}</code></td>
                            <td class="px-3 py-2 text-right text-gray-200">{{ round($s->observed_value, 3) }}</td>
                            <td class="px-3 py-2 text-right text-gray-400">{{ round($s->baseline_value, 3) }}</td>
                            <td class="px-3 py-2 text-right {{ abs($s->z_score ?? 0) >= 3 ? 'text-red-400 font-bold' : 'text-gray-300' }}">
                                {{ $s->z_score !== null ? number_format($s->z_score, 2) : '—' }}
                            </td>
                            <td class="px-3 py-2"><code class="text-gray-500">{{ $s->scoring_method }}</code></td>
                            <td class="px-3 py-2 text-center">
                                <span class="px-1.5 py-0.5 rounded text-xs {{ $s->confidence >= 0.75 ? 'bg-red-900/40 text-red-300' : ($s->confidence >= 0.60 ? 'bg-yellow-900/40 text-yellow-300' : 'bg-gray-700 text-gray-400') }}">
                                    {{ round($s->confidence * 100) }}%
                                </span>
                            </td>
                            <td class="px-3 py-2 text-gray-500">{{ \Carbon\Carbon::parse($s->scored_at)->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>

        <p class="text-xs text-gray-600 italic">Scoring methods: robust_z_score = (value − median) / (1.4826 × MAD). All scores are advisory-only — no automated action is taken.</p>
    </div>
</x-app-layout>
