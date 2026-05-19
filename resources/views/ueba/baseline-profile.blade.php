<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Baseline Profile</h2>
        <p class="text-xs text-amber-400/80 mt-1">Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.
        </div>

        <div class="flex justify-end">
            <a href="{{ route('ueba.dashboard') }}" class="text-xs text-cyan-400 hover:underline">← UEBA Dashboard</a>
        </div>

        {{-- Search form --}}
        <div class="glass-card p-4">
            <form method="GET" action="{{ route('ueba.baseline-profile') }}" class="flex flex-wrap gap-4 items-end">
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
                <button type="submit" class="px-4 py-2 text-xs rounded bg-cyan-900/50 text-cyan-300 border border-cyan-700/40 hover:bg-cyan-900/70">Load Profile</button>
            </form>
        </div>

        @if($profile)
            <div class="grid grid-cols-2 gap-6">
                {{-- Dimension baselines --}}
                <div class="glass-card p-4">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-sm font-semibold text-cyan-200">Behavioral Baselines — {{ $entityKey }}</h3>
                        <span class="text-xs text-gray-500">{{ count($profile['baselines']) }} dimensions</span>
                    </div>
                    @forelse($profile['baselines'] as $b)
                        <div class="border-b border-gray-800 py-2 text-xs">
                            <div class="flex justify-between">
                                <code class="text-cyan-400">{{ str_replace('_', ' ', $b['dimension']) }}</code>
                                <span class="text-gray-500">{{ $b['sample_count'] }} samples</span>
                            </div>
                            <div class="text-gray-400 mt-0.5">
                                Mean: <span class="text-gray-200">{{ number_format($b['baseline_mean'] ?? 0, 3) }}</span>
                                | Median: <span class="text-gray-200">{{ number_format($b['baseline_median'] ?? 0, 3) }}</span>
                                | σ: <span class="text-gray-200">{{ number_format($b['baseline_stddev'] ?? 0, 3) }}</span>
                            </div>
                            @if($b['peer_group_key'])
                                <div class="text-gray-600 text-xs mt-0.5">Peer: <a href="{{ route('ueba.peer-groups', ['peer_group_key' => $b['peer_group_key']]) }}" class="text-blue-400 hover:underline">{{ Str::limit($b['peer_group_key'], 30) }}</a></div>
                            @endif
                        </div>
                    @empty
                        <p class="text-gray-500 text-xs">No baselines computed yet for this entity.</p>
                    @endforelse
                </div>

                {{-- Recent anomaly scores --}}
                <div class="glass-card p-4">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-sm font-semibold text-cyan-200">Recent Anomaly Scores</h3>
                        <span class="text-xs text-yellow-400">{{ count($profile['anomaly_scores']) }}</span>
                    </div>
                    <div style="max-height: 380px; overflow-y: auto;">
                        @forelse($profile['anomaly_scores'] as $s)
                            <div class="border-b border-gray-800 py-2 text-xs">
                                <div class="flex justify-between">
                                    <strong class="text-gray-200">{{ str_replace('_', ' ', $s['anomaly_type']) }}</strong>
                                    <span class="px-1.5 py-0.5 rounded text-xs {{ ($s['confidence'] ?? 0) >= 0.75 ? 'bg-red-900/40 text-red-300' : (($s['confidence'] ?? 0) >= 0.60 ? 'bg-yellow-900/40 text-yellow-300' : 'bg-gray-700 text-gray-400') }}">
                                        {{ round(($s['confidence'] ?? 0) * 100) }}%
                                    </span>
                                </div>
                                <div class="text-gray-400 mt-0.5">
                                    Obs: <span class="text-gray-200">{{ round($s['observed_value'] ?? 0, 3) }}</span> vs
                                    Base: <span class="text-gray-200">{{ round($s['baseline_value'] ?? 0, 3) }}</span>
                                    | Z: {{ number_format($s['z_score'] ?? 0, 2) }}
                                    | <code class="text-gray-500">{{ $s['scoring_method'] ?? '' }}</code>
                                </div>
                                <div class="text-gray-600 text-xs">{{ \Carbon\Carbon::parse($s['scored_at'] ?? 'now')->diffForHumans() }}</div>
                            </div>
                        @empty
                            <p class="text-gray-500 text-xs">No anomalies scored for this entity.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            @if($profile['peer_group'])
                <div class="glass-card p-4">
                    <h3 class="text-sm font-semibold text-cyan-200 mb-2">Peer Group: {{ $profile['peer_group']['group_label'] }}</h3>
                    <div class="grid grid-cols-3 gap-4 text-xs">
                        <div><span class="text-gray-400">Key: </span><code class="text-cyan-400">{{ $profile['peer_group']['peer_group_key'] }}</code></div>
                        <div><span class="text-gray-400">Type: </span><span class="text-gray-200">{{ $profile['peer_group']['group_type'] }}</span></div>
                        <div><span class="text-gray-400">Members: </span><span class="text-gray-200">{{ $profile['peer_group']['entity_count'] }}</span></div>
                    </div>
                </div>
            @endif

            <p class="text-xs text-gray-600 italic">{{ $profile['disclaimer'] }}</p>
        @elseif($entityKey)
            <div class="glass-card p-4 text-gray-500 text-sm">No baseline data found for <strong class="text-gray-300">{{ $entityKey }}</strong> ({{ $entityType }}).</div>
        @endif
    </div>
</x-app-layout>
