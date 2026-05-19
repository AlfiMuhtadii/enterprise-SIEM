<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Cross-Domain Correlation</h2>
                <p class="text-xs text-amber-400/80 mt-1 font-medium">Cross-domain analytics are advisory-only and non-destructive.</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('cross-domain.identity-endpoint') }}" class="text-sm px-3 py-1.5 rounded bg-cyan-700/30 border border-cyan-400/30 text-cyan-200 hover:bg-cyan-700/50">Identity → Endpoint</a>
                <a href="{{ route('cross-domain.attack-timeline') }}" class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">Attack Timeline</a>
                <a href="{{ route('cross-domain.trace-explorer') }}" class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">Trace Explorer</a>
            </div>
        </div>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        @if(session('success'))
            <div class="rounded border border-green-400/30 bg-green-900/20 text-green-300 px-4 py-2 text-sm">{{ session('success') }}</div>
        @endif

        {{-- Advisory banner --}}
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3">
            <p class="text-sm text-amber-300">
                <strong>Advisory Notice:</strong> Cross-domain analytics correlate endpoint behavior with identity, cloud, and SaaS signals for investigation enrichment only.
                All correlations are retrospective and non-destructive. No autonomous response. No active containment.
            </p>
        </div>

        {{-- Run correlation --}}
        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Run Correlation Analysis</h3>
            <form method="POST" action="{{ route('cross-domain.run') }}" class="flex gap-3 items-center">
                @csrf
                <select name="type" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5">
                    <option value="identity_endpoint">Identity → Endpoint</option>
                    <option value="saas_endpoint">SaaS → Endpoint</option>
                    <option value="cloud_endpoint">Cloud → Endpoint</option>
                    <option value="multi_host">Multi-Host Shared Destination</option>
                </select>
                <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/50 border border-cyan-400/30 text-cyan-200 text-sm hover:bg-cyan-700/70">
                    Analyze
                </button>
                <span class="text-xs text-gray-400">Advisory-only. No mutations to source data.</span>
            </form>
        </div>

        {{-- Type breakdown --}}
        @if($typeBreakdown)
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach($typeBreakdown as $type => $count)
            <div class="rounded-lg border border-cyan-400/15 bg-gray-900/30 p-3 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $count }}</div>
                <div class="text-xs text-gray-400 mt-1">{{ str_replace('_', ' ', $type) }}</div>
            </div>
            @endforeach
        </div>
        @endif

        {{-- Attack stage distribution --}}
        @if($stageDistribution)
        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Attack Stage Distribution</h3>
            <div class="flex flex-wrap gap-2">
                @foreach($stageDistribution as $stage => $count)
                <a href="{{ route('cross-domain.attack-timeline', ['stage' => $stage]) }}"
                   class="px-3 py-1 rounded text-xs border border-cyan-400/20 bg-gray-800 text-cyan-300 hover:bg-gray-700">
                    {{ str_replace('_', ' ', $stage) }} ({{ $count }})
                </a>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Recent correlations --}}
        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Recent Correlations</h3>
            @if($recentCorrelations->isEmpty())
                <p class="text-gray-500 text-sm">No correlations yet. Run correlation analysis to begin.</p>
            @else
            <table class="w-full text-sm text-left text-gray-300">
                <thead class="text-xs text-gray-400 uppercase border-b border-gray-700">
                    <tr>
                        <th class="py-2 pr-4">ID</th>
                        <th class="py-2 pr-4">Type</th>
                        <th class="py-2 pr-4">Actor</th>
                        <th class="py-2 pr-4">Stages</th>
                        <th class="py-2 pr-4">Confidence</th>
                        <th class="py-2">Created</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentCorrelations as $corr)
                    <tr class="border-b border-gray-800 hover:bg-gray-800/30">
                        <td class="py-2 pr-4">
                            <a href="{{ route('cross-domain.show', $corr->correlation_id) }}" class="text-cyan-400 hover:underline font-mono text-xs">{{ $corr->correlation_id }}</a>
                        </td>
                        <td class="py-2 pr-4 text-xs">{{ str_replace('_', ' ', $corr->correlation_type) }}</td>
                        <td class="py-2 pr-4 text-xs font-mono">{{ $corr->actor_key ?? '—' }}</td>
                        <td class="py-2 pr-4 text-xs">
                            @foreach($corr->attack_stages ?? [] as $s)
                                <span class="inline-block px-1.5 py-0.5 rounded bg-cyan-900/40 text-cyan-300 mr-1">{{ str_replace('_', ' ', $s) }}</span>
                            @endforeach
                        </td>
                        <td class="py-2 pr-4 text-xs">{{ number_format($corr->confidence_score * 100) }}%</td>
                        <td class="py-2 text-xs text-gray-500">{{ $corr->created_at?->diffForHumans() }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </div>
    </div>
</x-app-layout>
