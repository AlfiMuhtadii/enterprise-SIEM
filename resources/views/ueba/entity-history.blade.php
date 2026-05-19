<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Entity Baseline History</h2>
        <p class="text-xs text-amber-400/80 mt-1">Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.
        </div>

        <div class="flex justify-end">
            <a href="{{ route('ueba.dashboard') }}" class="text-xs text-cyan-400 hover:underline">← UEBA Dashboard</a>
        </div>

        {{-- Search --}}
        <div class="glass-card p-4">
            <form method="GET" action="{{ route('ueba.entity-history') }}" class="flex flex-wrap gap-4 items-end">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Entity Key</label>
                    <input type="text" name="entity_key" class="bg-gray-800 border border-gray-600 text-gray-200 text-xs rounded px-3 py-2 w-72" value="{{ $entityKey }}" placeholder="e.g. alice@example.com or DESKTOP-XYZ">
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Entity Type</label>
                    <select name="entity_type" class="bg-gray-800 border border-gray-600 text-gray-200 text-xs rounded px-3 py-2">
                        @foreach($entityTypes as $t)
                            <option value="{{ $t }}" @selected($t === $entityType)>{{ ucfirst($t) }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="px-4 py-2 text-xs rounded bg-cyan-900/50 text-cyan-300 border border-cyan-700/40 hover:bg-cyan-900/70">Load History</button>
            </form>
        </div>

        @if($entityKey)
            <div class="grid grid-cols-2 gap-6">
                {{-- Current baselines --}}
                <div class="glass-card p-4">
                    <h3 class="text-sm font-semibold text-cyan-200 mb-3">Current Baselines ({{ $baselines->count() }} dimensions)</h3>
                    @forelse($baselines as $b)
                        <div class="border-b border-gray-800 py-2 text-xs">
                            <div class="flex justify-between">
                                <strong class="text-gray-200">{{ str_replace('_', ' ', $b->dimension) }}</strong>
                                <span class="text-gray-500">{{ $b->sample_count }} samples</span>
                            </div>
                            <div class="text-gray-400 mt-0.5">
                                Mean: {{ number_format($b->baseline_mean ?? 0, 3) }} |
                                Median: {{ number_format($b->baseline_median ?? 0, 3) }} |
                                σ: {{ number_format($b->baseline_stddev ?? 0, 3) }}
                            </div>
                            @if($b->peer_group_key)
                                <div class="text-gray-600 mt-0.5">Peer: <a href="{{ route('ueba.peer-groups', ['peer_group_key' => $b->peer_group_key]) }}" class="text-blue-400 hover:underline">{{ $b->peer_group_key }}</a></div>
                            @endif
                        </div>
                    @empty
                        <p class="text-gray-500 text-xs">No baselines for this entity.</p>
                    @endforelse
                </div>

                {{-- Anomaly timeline --}}
                <div class="glass-card p-0 overflow-hidden">
                    <div class="flex justify-between items-center px-4 py-3 border-b border-gray-700">
                        <span class="text-sm font-semibold text-cyan-200">Anomaly Timeline</span>
                        <span class="text-xs text-yellow-400">{{ $history->count() }} events</span>
                    </div>
                    <div style="max-height: 480px; overflow-y: auto;">
                        @forelse($history as $s)
                            <div class="border-b border-gray-800 px-4 py-2 text-xs">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <strong class="text-gray-200">{{ str_replace('_', ' ', $s->anomaly_type) }}</strong>
                                        <span class="text-gray-500 ml-2">{{ $s->dimension }}</span>
                                    </div>
                                    <span class="px-1.5 py-0.5 rounded {{ $s->confidence >= 0.75 ? 'bg-red-900/40 text-red-300' : ($s->confidence >= 0.60 ? 'bg-yellow-900/40 text-yellow-300' : 'bg-gray-700 text-gray-400') }}">
                                        {{ round($s->confidence * 100) }}%
                                    </span>
                                </div>
                                <div class="text-gray-400 mt-0.5">
                                    Obs: <span class="text-gray-200">{{ round($s->observed_value, 3) }}</span> vs
                                    Base: <span class="text-gray-200">{{ round($s->baseline_value, 3) }}</span>
                                    | Z: {{ $s->z_score !== null ? number_format($s->z_score, 2) : '—' }}
                                    | <code class="text-gray-500">{{ $s->scoring_method }}</code>
                                </div>
                                <div class="text-gray-600 mt-0.5">{{ \Carbon\Carbon::parse($s->scored_at)->toDateTimeString() }} ({{ \Carbon\Carbon::parse($s->scored_at)->diffForHumans() }})</div>
                            </div>
                        @empty
                            <div class="text-center text-gray-500 text-xs py-6">No anomaly history found for this entity.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <p class="text-xs text-gray-600 italic">Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.</p>
        @endif
    </div>
</x-app-layout>
