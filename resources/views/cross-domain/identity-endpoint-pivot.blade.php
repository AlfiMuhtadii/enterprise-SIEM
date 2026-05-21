<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Identity → Endpoint Pivot</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Advisory-only retrospective investigation. No active response.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Identity-to-endpoint correlation is advisory-only and non-destructive.
            Correlations indicate possible investigation paths, not confirmed attacks.
        </div>

        <form method="GET" class="flex gap-3 items-center">
            <input type="text" name="actor_key" value="{{ $actorKey }}" placeholder="actor_key (e.g. user@domain.com)"
                   class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-72" />
            <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm hover:bg-cyan-700/60">Pivot</button>
        </form>

        @if($actorKey && !empty($result))
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            {{-- Identity alerts --}}
            <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-cyan-200 mb-2">Identity Alerts ({{ count($result['identity_alerts'] ?? []) }})</h3>
                @forelse($result['identity_alerts'] ?? [] as $a)
                <div class="text-xs p-1.5 rounded bg-gray-800/30 mb-1">
                    <span class="text-gray-400 font-mono">{{ $a->alert_type ?? $a['alert_type'] ?? '' }}</span>
                    <span class="ml-2 text-gray-500">{{ $a->detected_at ?? $a['detected_at'] ?? '' }}</span>
                </div>
                @empty
                <p class="text-xs text-gray-500">No identity alerts found.</p>
                @endforelse
            </div>

            {{-- Correlations --}}
            <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-cyan-200 mb-2">Correlations ({{ count($result['correlations'] ?? []) }})</h3>
                @forelse($result['correlations'] ?? [] as $c)
                <div class="text-xs p-1.5 rounded bg-gray-800/30 mb-1">
                    <a href="{{ route('cross-domain.show', $c->correlation_id ?? $c['correlation_id'] ?? '') }}" class="text-cyan-400 hover:underline font-mono">
                        {{ $c->correlation_id ?? $c['correlation_id'] ?? '' }}
                    </a>
                    <span class="ml-2 text-gray-400">{{ number_format(($c->confidence_score ?? $c['confidence_score'] ?? 0) * 100) }}%</span>
                </div>
                @empty
                <p class="text-xs text-gray-500">No correlations found for this actor.</p>
                @endforelse
            </div>
        </div>
        @endif

        {{-- Recent identity-endpoint correlations --}}
        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Recent Identity → Endpoint Correlations</h3>
            @if($recentIdentityCorrelations->isEmpty())
                <p class="text-sm text-gray-500">No identity-endpoint correlations yet.</p>
            @else
            <div class="overflow-x-auto">
            <table class="w-full text-xs text-left text-gray-300">
                <thead class="text-gray-400 uppercase border-b border-gray-700">
                    <tr><th class="py-2 pr-3">ID</th><th class="py-2 pr-3">Actor</th><th class="py-2 pr-3">Stages</th><th class="py-2 pr-3">Confidence</th><th class="py-2">Created</th></tr>
                </thead>
                <tbody>
                    @foreach($recentIdentityCorrelations as $corr)
                    <tr class="border-b border-gray-800 hover:bg-gray-800/20">
                        <td class="py-1.5 pr-3"><a href="{{ route('cross-domain.show', $corr->correlation_id) }}" class="text-cyan-400 hover:underline font-mono">{{ $corr->correlation_id }}</a></td>
                        <td class="py-1.5 pr-3 font-mono text-gray-400">{{ $corr->actor_key ?? '—' }}</td>
                        <td class="py-1.5 pr-3">{{ implode(' → ', array_map(fn($s) => str_replace('_', ' ', $s), $corr->attack_stages ?? [])) }}</td>
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
