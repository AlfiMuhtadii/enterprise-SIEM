<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Shared Destination Investigation</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Advisory-only. Multi-host outbound correlation. Non-destructive.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Shared destination correlation is advisory-only and non-destructive.
            No IP blocks, no host isolation, no automated response.
        </div>

        <form method="GET" class="flex gap-3 items-center">
            <input type="text" name="ip" value="{{ $ip }}" placeholder="Destination IP"
                   class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-48 font-mono" />
            <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm">Investigate</button>
        </form>

        @if($ip && !empty($result))
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-cyan-200 mb-2">Beacon Patterns ({{ count($result['beacons'] ?? []) }})</h3>
                @forelse($result['beacons'] ?? [] as $b)
                <div class="text-xs p-1.5 rounded bg-gray-800/30 mb-1 flex gap-3">
                    <span class="text-cyan-400 font-mono">{{ $b->process_name ?? $b['process_name'] ?? '?' }}</span>
                    <span class="text-gray-400">×{{ $b->connection_count ?? $b['connection_count'] ?? 0 }}</span>
                </div>
                @empty<p class="text-xs text-gray-500">No beacons to this IP.</p>@endforelse
            </div>
            <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-cyan-200 mb-2">Multi-Host Correlations ({{ count($result['correlations'] ?? []) }})</h3>
                @forelse($result['correlations'] ?? [] as $c)
                <div class="text-xs p-1.5 rounded bg-gray-800/30 mb-1">
                    <a href="{{ route('cross-domain.show', $c->correlation_id ?? $c['correlation_id'] ?? '') }}" class="text-cyan-400 hover:underline font-mono">{{ $c->correlation_id ?? $c['correlation_id'] ?? '' }}</a>
                    <span class="ml-2 text-gray-400">{{ number_format(($c->confidence_score ?? $c['confidence_score'] ?? 0) * 100) }}%</span>
                </div>
                @empty<p class="text-xs text-gray-500">No multi-host correlations for this IP.</p>@endforelse
            </div>
        </div>
        @endif

        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Recent Multi-Host Correlations</h3>
            @if($multiHostCorrelations->isEmpty())
                <p class="text-sm text-gray-500">No multi-host correlations yet.</p>
            @else
            <div class="overflow-x-auto">
            <table class="w-full text-xs text-left text-gray-300">
                <thead class="text-gray-400 uppercase border-b border-gray-700">
                    <tr><th class="py-2 pr-3">ID</th><th class="py-2 pr-3">Destination</th><th class="py-2 pr-3">Hosts</th><th class="py-2 pr-3">Confidence</th><th class="py-2">Created</th></tr>
                </thead>
                <tbody>
                    @foreach($multiHostCorrelations as $corr)
                    <tr class="border-b border-gray-800 hover:bg-gray-800/20">
                        <td class="py-1.5 pr-3"><a href="{{ route('cross-domain.show', $corr->correlation_id) }}" class="text-cyan-400 hover:underline font-mono">{{ $corr->correlation_id }}</a></td>
                        <td class="py-1.5 pr-3 font-mono">{{ $corr->primary_entity_key ?? '—' }}</td>
                        <td class="py-1.5 pr-3">{{ count($corr->involved_hosts ?? []) }}</td>
                        <td class="py-1.5 pr-3">{{ number_format($corr->confidence_score * 100) }}%</td>
                        <td class="py-1.5 text-gray-500">{{ $corr->created_at?->diffForHumans() }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            @endif
        </div>
    </div>
</x-app-layout>
