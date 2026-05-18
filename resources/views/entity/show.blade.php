<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="brand-chip">Entity Graph</p>
                <h2 class="mt-2 text-xl font-semibold leading-tight text-main-ui">Entity Detail</h2>
                <p class="mono-ui mt-1 truncate text-xs text-cyan-200/55">{{ $entity->entity_key }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('entity.index') }}"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    ← Search
                </a>
                <a href="{{ route('entity.timeline', $entity->id) }}"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    Timeline
                </a>
                <a href="{{ route('entity.graph', $entity->id) }}"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    Graph
                </a>
                <a href="{{ route('entity.risk-breakdown', $entity->id) }}"
                   class="rounded-lg border border-orange-400/40 bg-orange-500/10 px-3 py-1.5 text-sm text-orange-200 hover:bg-orange-500/20 transition">
                    Risk →
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-5">

        {{-- Entity summary --}}
        @php
            $typeColor = match($entity->entity_type) {
                'user'      => 'text-cyan-300 border-cyan-400/40 bg-cyan-500/10',
                'host'      => 'text-blue-300 border-blue-400/40 bg-blue-500/10',
                'process'   => 'text-purple-300 border-purple-400/40 bg-purple-500/10',
                'ip'        => 'text-orange-300 border-orange-400/40 bg-orange-500/10',
                'domain'    => 'text-green-300 border-green-400/40 bg-green-500/10',
                'file_hash' => 'text-red-300 border-red-400/40 bg-red-500/10',
                'alert'     => 'text-yellow-300 border-yellow-400/40 bg-yellow-500/10',
                'incident'  => 'text-pink-300 border-pink-400/40 bg-pink-500/10',
                'trace'     => 'text-gray-300 border-gray-400/40 bg-gray-500/10',
                default     => 'text-cyan-100/50 border-cyan-200/20 bg-black/20',
            };
        @endphp

        <div class="glass-card p-5">
            <div class="flex flex-wrap items-start gap-6">
                <div>
                    <p class="text-xs text-cyan-200/40 mb-1">Type</p>
                    <span class="rounded border px-2 py-1 text-sm font-medium {{ $typeColor }}">
                        {{ $entity->entity_type }}
                    </span>
                </div>
                <div class="flex-1 min-w-48">
                    <p class="text-xs text-cyan-200/40 mb-1">Entity Key</p>
                    <p class="mono-ui text-sm text-cyan-50 break-all">{{ $entity->entity_key }}</p>
                </div>
                @if ($entity->display_name && $entity->display_name !== $entity->entity_key)
                    <div class="flex-1 min-w-48">
                        <p class="text-xs text-cyan-200/40 mb-1">Display Name</p>
                        <p class="text-sm text-cyan-200">{{ $entity->display_name }}</p>
                    </div>
                @endif
            </div>

            @php
                $riskLevel = $entity->risk_level ?? null;
                $riskScore = $entity->risk_score ?? null;
                $riskBadgeColor = match($riskLevel) {
                    'critical' => 'border-red-400/50 bg-red-500/10 text-red-300',
                    'high'     => 'border-orange-400/50 bg-orange-500/10 text-orange-300',
                    'medium'   => 'border-yellow-400/50 bg-yellow-500/10 text-yellow-300',
                    'low'      => 'border-green-400/50 bg-green-500/10 text-green-300',
                    default    => null,
                };
            @endphp

            <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ([
                    ['label' => 'Observations', 'value' => $entity->observation_count],
                    ['label' => 'First Seen', 'value' => $entity->first_seen_at ? \Carbon\Carbon::parse($entity->first_seen_at)->format('Y-m-d H:i') : '—'],
                    ['label' => 'Last Seen',  'value' => $entity->last_seen_at  ? \Carbon\Carbon::parse($entity->last_seen_at)->format('Y-m-d H:i')  : '—'],
                ] as $m)
                    <div class="glass-card px-4 py-3">
                        <p class="text-xs text-cyan-200/45">{{ $m['label'] }}</p>
                        <p class="mt-1 text-base font-semibold text-cyan-50 mono-ui">{{ $m['value'] }}</p>
                    </div>
                @endforeach

                {{-- Risk score tile --}}
                @if ($riskBadgeColor)
                    <a href="{{ route('entity.risk-breakdown', $entity->id) }}"
                       class="glass-card px-4 py-3 rounded-lg border {{ $riskBadgeColor }} hover:opacity-80 transition block">
                        <p class="text-xs opacity-60">Risk Score</p>
                        <p class="mt-1 text-base font-bold mono-ui">{{ number_format($riskScore, 1) }}</p>
                        <p class="text-[10px] uppercase tracking-[0.1em] opacity-50 mt-0.5">{{ $riskLevel }}</p>
                    </a>
                @endif
            </div>
        </div>

        {{-- Relationships --}}
        @if ($relationships->isNotEmpty())
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                        Relationships ({{ $relationships->count() }})
                    </h3>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.09em] text-cyan-200/40">
                            <th class="px-5 py-3">Direction</th>
                            <th class="px-4 py-3">Relationship</th>
                            <th class="px-4 py-3">Peer</th>
                            <th class="px-4 py-3">Peer Key</th>
                            <th class="px-4 py-3 text-center">Obs</th>
                            <th class="px-4 py-3">Last Seen</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($relationships as $rel)
                            <tr class="hover:bg-cyan-100/3 transition">
                                <td class="px-5 py-3">
                                    <span class="text-xs {{ $rel->direction === 'outbound' ? 'text-cyan-400' : 'text-orange-400' }}">
                                        {{ $rel->direction === 'outbound' ? '→ out' : '← in' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="mono-ui text-xs text-cyan-200/70">{{ $rel->relationship_type }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded border px-1.5 py-0.5 text-xs text-cyan-200/60 border-cyan-200/20 bg-cyan-100/5">
                                        {{ $rel->peer_type }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="mono-ui text-xs text-cyan-200/70">{{ Str::limit($rel->peer_key, 40) }}</span>
                                </td>
                                <td class="px-4 py-3 text-center text-xs text-cyan-200/60">{{ $rel->observation_count }}</td>
                                <td class="px-4 py-3 text-xs text-cyan-200/50">
                                    {{ $rel->last_seen_at ? \Carbon\Carbon::parse($rel->last_seen_at)->format('Y-m-d H:i') : '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('entity.show', $rel->peer_id) }}"
                                       class="text-xs text-cyan-400 hover:text-cyan-200 transition">Pivot →</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="grid gap-5 lg:grid-cols-2">
            {{-- Related Alerts --}}
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                        Related Alerts ({{ $alerts->count() }})
                    </h3>
                </div>
                @if ($alerts->isNotEmpty())
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.09em] text-cyan-200/40">
                                <th class="px-5 py-3">Alert Type</th>
                                <th class="px-4 py-3">Severity</th>
                                <th class="px-4 py-3">Detected At</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyan-100/5">
                            @foreach ($alerts->take(10) as $alert)
                                @php
                                    $sev = $alert->severity ?? 'medium';
                                    $sevColor = match($sev) {
                                        'critical' => 'text-red-300 border-red-400/40 bg-red-500/10',
                                        'high'     => 'text-orange-300 border-orange-400/40 bg-orange-500/10',
                                        'medium'   => 'text-yellow-300 border-yellow-400/40 bg-yellow-500/10',
                                        default    => 'text-cyan-300 border-cyan-400/40 bg-cyan-500/10',
                                    };
                                @endphp
                                <tr class="hover:bg-cyan-100/3 transition">
                                    <td class="px-5 py-2.5">
                                        <span class="mono-ui text-xs text-cyan-200/70">{{ $alert->alert_type ?? '—' }}</span>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <span class="rounded border px-1.5 py-0.5 text-xs {{ $sevColor }}">
                                            {{ strtoupper($sev) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5 text-xs text-cyan-200/50">
                                        {{ isset($alert->detected_at) ? \Carbon\Carbon::parse($alert->detected_at)->format('Y-m-d H:i') : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($alerts->count() > 10)
                        <div class="border-t border-cyan-100/10 px-5 py-3 text-xs text-cyan-200/40">
                            Showing 10 of {{ $alerts->count() }}. Use
                            <a href="{{ route('api.entities.alerts', $entity->id) }}" target="_blank" class="text-cyan-400 hover:text-cyan-200">API</a>
                            for full list.
                        </div>
                    @endif
                @else
                    <div class="px-5 py-6 text-xs text-cyan-200/30 text-center">No alerts linked to this entity.</div>
                @endif
            </div>

            {{-- Related Incidents --}}
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                        Related Incidents ({{ $incidents->count() }})
                    </h3>
                </div>
                @if ($incidents->isNotEmpty())
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.09em] text-cyan-200/40">
                                <th class="px-5 py-3">Incident</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">First Seen</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyan-100/5">
                            @foreach ($incidents->take(10) as $inc)
                                <tr class="hover:bg-cyan-100/3 transition">
                                    <td class="px-5 py-2.5">
                                        <span class="text-xs text-cyan-200/70">{{ Str::limit($inc->title ?? $inc->incident_id, 36) }}</span>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <span class="mono-ui text-xs text-cyan-200/50">{{ $inc->status ?? '—' }}</span>
                                    </td>
                                    <td class="px-4 py-2.5 text-xs text-cyan-200/50">
                                        {{ isset($inc->first_seen_at) ? \Carbon\Carbon::parse($inc->first_seen_at)->format('Y-m-d H:i') : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="px-5 py-6 text-xs text-cyan-200/30 text-center">No incidents linked to this entity.</div>
                @endif
            </div>
        </div>

        {{-- Recent Observations --}}
        @if ($observations->isNotEmpty())
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3 flex items-center justify-between">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">Recent Observations</h3>
                    <a href="{{ route('entity.timeline', $entity->id) }}"
                       class="text-xs text-cyan-400 hover:text-cyan-200 transition">Full timeline →</a>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.09em] text-cyan-200/40">
                            <th class="px-5 py-3">Observed At</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Source</th>
                            <th class="px-4 py-3">Trace</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($observations as $obs)
                            <tr class="hover:bg-cyan-100/3 transition">
                                <td class="px-5 py-2.5 text-xs text-cyan-200/60 mono-ui">
                                    {{ \Carbon\Carbon::parse($obs->observed_at)->format('Y-m-d H:i:s') }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="mono-ui text-xs text-cyan-200/70">{{ $obs->observation_type }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/50">{{ $obs->source_table ?? '—' }}</td>
                                <td class="px-4 py-2.5">
                                    @if ($obs->trace_id)
                                        <a href="{{ route('traces.show', $obs->trace_id) }}"
                                           class="mono-ui text-xs text-cyan-400 hover:text-cyan-200 transition">
                                            {{ Str::limit($obs->trace_id, 20) }}
                                        </a>
                                    @else
                                        <span class="text-xs text-cyan-200/25">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

    </div>
</x-app-layout>
