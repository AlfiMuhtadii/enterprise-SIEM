<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Correlation: {{ $correlation->correlation_id }}</h2>
            <p class="text-xs text-amber-400/80 mt-1 font-medium">Advisory-only. Non-destructive. No automated response.</p>
        </div>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3">
            <p class="text-sm text-amber-300">Cross-domain analytics are advisory-only and non-destructive. This correlation enriches investigation context only.</p>
        </div>

        {{-- Summary --}}
        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><span class="text-gray-400 text-xs">Type</span><div class="text-cyan-200 font-medium">{{ str_replace('_', ' ', $correlation->correlation_type) }}</div></div>
            <div><span class="text-gray-400 text-xs">Confidence</span><div class="text-cyan-200 font-medium">{{ number_format($correlation->confidence_score * 100) }}%</div></div>
            <div><span class="text-gray-400 text-xs">Actor</span><div class="text-cyan-200 font-mono text-xs">{{ $correlation->actor_key ?? '—' }}</div></div>
            <div><span class="text-gray-400 text-xs">Created</span><div class="text-gray-400 text-xs">{{ $correlation->created_at?->toDateTimeString() }}</div></div>
        </div>

        {{-- Attack stages --}}
        @if($correlation->timelines->isNotEmpty())
        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Attack Stage Timeline</h3>
            <div class="space-y-2">
                @foreach($correlation->timelines->sortBy('stage_order') as $timeline)
                <div class="flex items-start gap-3 p-2 rounded bg-gray-800/30 border border-gray-700/30">
                    <span class="text-xs font-bold text-cyan-400 w-6 text-center mt-0.5">{{ $timeline->stage_order }}</span>
                    <div class="flex-1">
                        <span class="text-sm font-medium text-gray-200">{{ str_replace('_', ' ', $timeline->stage) }}</span>
                        <span class="text-xs text-gray-400 ml-2">{{ number_format($timeline->stage_confidence * 100) }}% confidence</span>
                        @if($timeline->evidence_summary)
                        <p class="text-xs text-gray-500 mt-0.5">{{ $timeline->evidence_summary }}</p>
                        @endif
                    </div>
                    <div class="text-xs text-gray-500 text-right">
                        <div>{{ $timeline->first_seen_at?->format('H:i:s') }}</div>
                        @if($timeline->last_seen_at && $timeline->last_seen_at != $timeline->first_seen_at)
                        <div>→ {{ $timeline->last_seen_at?->format('H:i:s') }}</div>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Involved entities --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @if($correlation->involved_hosts)
            <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-3">
                <h4 class="text-xs font-semibold text-gray-400 uppercase mb-2">Hosts ({{ count($correlation->involved_hosts) }})</h4>
                @foreach(array_slice($correlation->involved_hosts, 0, 5) as $host)
                    <div class="text-xs font-mono text-gray-300">{{ $host }}</div>
                @endforeach
            </div>
            @endif
            @if($correlation->involved_users)
            <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-3">
                <h4 class="text-xs font-semibold text-gray-400 uppercase mb-2">Users ({{ count($correlation->involved_users) }})</h4>
                @foreach(array_slice($correlation->involved_users, 0, 5) as $user)
                    <div class="text-xs font-mono text-gray-300">{{ $user }}</div>
                @endforeach
            </div>
            @endif
            @if($correlation->involved_ips)
            <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-3">
                <h4 class="text-xs font-semibold text-gray-400 uppercase mb-2">IPs ({{ count($correlation->involved_ips) }})</h4>
                @foreach(array_slice($correlation->involved_ips, 0, 5) as $ip)
                    <div class="text-xs font-mono text-gray-300">{{ $ip }}</div>
                @endforeach
            </div>
            @endif
        </div>

        {{-- Evidence links --}}
        @if($correlation->evidenceLinks->isNotEmpty())
        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Evidence Links ({{ $correlation->evidenceLinks->count() }})</h3>
            <div class="space-y-1">
                @foreach($correlation->evidenceLinks->take(15) as $link)
                <div class="flex items-center gap-3 text-xs text-gray-300 p-1.5 rounded bg-gray-800/20">
                    <span class="px-1.5 py-0.5 rounded bg-gray-700 text-gray-400">{{ $link->domain }}</span>
                    <span class="text-gray-400">{{ $link->evidence_type }}</span>
                    <span class="font-mono text-gray-500">{{ $link->evidence_table }}</span>
                    <span class="text-gray-600">id={{ $link->evidence_id ?? '—' }}</span>
                </div>
                @endforeach
            </div>
        </div>
        @endif

        <div class="text-xs text-gray-600 text-center pt-2">
            Trace ID: <span class="font-mono">{{ $correlation->trace_id }}</span> &middot;
            Window: {{ $correlation->time_window_start }} → {{ $correlation->time_window_end }}
        </div>

        <a href="{{ route('cross-domain.dashboard') }}" class="inline-block text-sm text-cyan-400 hover:underline">← Back to Dashboard</a>
    </div>
</x-app-layout>
