<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Multi-Stage Investigation Graph</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Advisory-only. Non-destructive. Cross-domain analytics for investigation enrichment.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Cross-domain analytics are advisory-only and non-destructive.
            No automated response. No active containment. No process kill. No host isolation.
        </div>

        <form method="GET" class="flex gap-3 items-center">
            <input type="text" name="correlation_id" value="{{ $correlationId }}" placeholder="CORR-YYYY-NNNNN"
                   class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-56 font-mono" />
            <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm">Load Graph</button>
        </form>

        @if($correlation)
        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4 space-y-4">
            <div class="flex gap-6 text-sm">
                <div><span class="text-gray-400 text-xs">ID</span><div class="font-mono text-cyan-300">{{ $correlation->correlation_id }}</div></div>
                <div><span class="text-gray-400 text-xs">Type</span><div class="text-gray-200">{{ str_replace('_', ' ', $correlation->correlation_type) }}</div></div>
                <div><span class="text-gray-400 text-xs">Actor</span><div class="font-mono text-gray-300">{{ $correlation->actor_key ?? '—' }}</div></div>
                <div><span class="text-gray-400 text-xs">Confidence</span><div class="text-gray-200">{{ number_format($correlation->confidence_score * 100) }}%</div></div>
            </div>

            {{-- Stage chain visualization --}}
            @if($correlation->timelines->isNotEmpty())
            <div>
                <p class="text-xs text-gray-400 mb-2 uppercase">Attack Progression</p>
                <div class="flex flex-wrap items-center gap-1">
                    @foreach($correlation->timelines->sortBy('stage_order') as $i => $t)
                        @if($i > 0)<span class="text-cyan-600">→</span>@endif
                        <span class="px-3 py-1.5 rounded border text-xs font-medium
                            {{ in_array($t->stage, ['initial_access','credential_access']) ? 'border-yellow-500/40 bg-yellow-900/20 text-yellow-300'
                            : (in_array($t->stage, ['execution','persistence']) ? 'border-orange-500/40 bg-orange-900/20 text-orange-300'
                            : 'border-red-500/40 bg-red-900/20 text-red-300') }}">
                            {{ str_replace('_', ' ', $t->stage) }}
                        </span>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Entity involvement --}}
            <div class="grid grid-cols-3 gap-3 text-xs">
                @if($correlation->involved_hosts)
                <div class="rounded bg-gray-800/40 p-2"><p class="text-gray-400 mb-1">Hosts</p>
                    @foreach(array_slice($correlation->involved_hosts, 0, 3) as $h)<div class="font-mono text-cyan-300">{{ $h }}</div>@endforeach</div>
                @endif
                @if($correlation->involved_users)
                <div class="rounded bg-gray-800/40 p-2"><p class="text-gray-400 mb-1">Users</p>
                    @foreach(array_slice($correlation->involved_users, 0, 3) as $u)<div class="font-mono text-gray-300">{{ $u }}</div>@endforeach</div>
                @endif
                @if($correlation->involved_ips)
                <div class="rounded bg-gray-800/40 p-2"><p class="text-gray-400 mb-1">IPs</p>
                    @foreach(array_slice($correlation->involved_ips, 0, 3) as $ip)<div class="font-mono text-gray-300">{{ $ip }}</div>@endforeach</div>
                @endif
            </div>

            <div class="pt-2">
                <a href="{{ route('cross-domain.show', $correlation->correlation_id) }}" class="text-sm text-cyan-400 hover:underline">View full detail →</a>
            </div>
        </div>
        @endif

        <p class="text-xs text-gray-500 text-center">
            All graph data is advisory-only and reflects retrospective behavioral analysis.
            No nodes in this graph trigger automated actions.
        </p>
    </div>
</x-app-layout>
