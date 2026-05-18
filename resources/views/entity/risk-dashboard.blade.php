<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="brand-chip">Entity Risk</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">Risk Dashboard</h2>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs uppercase tracking-[0.14em] text-cyan-100/40">Investigation Prioritization — Advisory Only</span>
                <a href="{{ route('api.entity-risk.top') }}" target="_blank" rel="noopener"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    JSON
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">

        {{-- Advisory notice --}}
        <div class="rounded-lg border border-yellow-400/20 bg-yellow-500/5 px-4 py-3 text-xs text-yellow-200/70">
            Risk scores are investigation prioritization aids only. They do not trigger automatic enforcement, containment, or incident creation.
            Shadow domain contributions are marked <span class="mono-ui">advisory_only</span>.
        </div>

        {{-- Risk level distribution --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            @foreach ([
                ['level' => 'critical', 'color' => 'text-red-300 border-red-400/40 bg-red-500/10'],
                ['level' => 'high',     'color' => 'text-orange-300 border-orange-400/40 bg-orange-500/10'],
                ['level' => 'medium',   'color' => 'text-yellow-300 border-yellow-400/40 bg-yellow-500/10'],
                ['level' => 'low',      'color' => 'text-green-300 border-green-400/40 bg-green-500/10'],
                ['level' => 'total',    'color' => 'text-cyan-300 border-cyan-400/40 bg-cyan-500/10'],
            ] as $lvl)
                @php
                    $cnt = $lvl['level'] === 'total'
                        ? $totalScored
                        : (int) ($distribution->get($lvl['level'])?->count ?? 0);
                @endphp
                <div class="glass-card px-4 py-3 border-l-2 {{ $lvl['color'] }}">
                    <p class="text-xs uppercase tracking-[0.12em] opacity-60">{{ $lvl['level'] }}</p>
                    <p class="mt-1 text-2xl font-bold mono-ui">{{ $cnt }}</p>
                </div>
            @endforeach
        </div>

        <div class="grid gap-5 lg:grid-cols-2">

            {{-- Top Risky Users --}}
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3 flex items-center justify-between">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">Top Risky Users</h3>
                    <span class="text-xs text-cyan-200/30">{{ $topUsers->count() }} shown</span>
                </div>
                @include('entity._risk-table', ['rows' => $topUsers])
            </div>

            {{-- Top Risky Hosts --}}
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3 flex items-center justify-between">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">Top Risky Hosts</h3>
                    <span class="text-xs text-cyan-200/30">{{ $topHosts->count() }} shown</span>
                </div>
                @include('entity._risk-table', ['rows' => $topHosts])
            </div>

            {{-- Top Risky IPs --}}
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3 flex items-center justify-between">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">Top Risky IPs</h3>
                    <span class="text-xs text-cyan-200/30">{{ $topIps->count() }} shown</span>
                </div>
                @include('entity._risk-table', ['rows' => $topIps])
            </div>

            {{-- Top Risky Domains --}}
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3 flex items-center justify-between">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">Top Risky Domains</h3>
                    <span class="text-xs text-cyan-200/30">{{ $topDomains->count() }} shown</span>
                </div>
                @include('entity._risk-table', ['rows' => $topDomains])
            </div>

        </div>

        {{-- Recent High/Critical Snapshots (Risk Trend View) --}}
        @if ($recentSnapshots->isNotEmpty())
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                        Recent High/Critical Risk Events ({{ $recentSnapshots->count() }})
                    </h3>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.09em] text-cyan-200/40">
                            <th class="px-5 py-3">Type</th>
                            <th class="px-4 py-3">Entity Key</th>
                            <th class="px-4 py-3 text-center">Score</th>
                            <th class="px-4 py-3">Level</th>
                            <th class="px-4 py-3">Calculated At</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($recentSnapshots as $snap)
                            @php
                                $lvlColor = match($snap->risk_level) {
                                    'critical' => 'text-red-300 border-red-400/40 bg-red-500/10',
                                    'high'     => 'text-orange-300 border-orange-400/40 bg-orange-500/10',
                                    default    => 'text-cyan-200/50 border-cyan-200/20 bg-black/20',
                                };
                            @endphp
                            <tr class="hover:bg-cyan-100/3 transition">
                                <td class="px-5 py-2.5">
                                    <span class="rounded border px-1.5 py-0.5 text-xs text-cyan-200/60 border-cyan-200/20 bg-cyan-100/5">
                                        {{ $snap->entity_type }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="mono-ui text-xs text-cyan-200/75">{{ Str::limit($snap->entity_key, 40) }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <span class="mono-ui text-xs font-bold text-cyan-50">{{ number_format($snap->risk_score, 1) }}</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded border px-1.5 py-0.5 text-xs {{ $lvlColor }}">
                                        {{ strtoupper($snap->risk_level) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/50 mono-ui">
                                    {{ \Carbon\Carbon::parse($snap->calculated_at)->format('Y-m-d H:i') }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('entity.show', $snap->entity_id) }}"
                                       class="text-xs text-cyan-400 hover:text-cyan-200 transition">
                                        Detail →
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="glass-card p-8 text-center">
                <p class="text-sm text-cyan-200/40">No risk scores calculated yet.</p>
                <p class="mt-1 text-xs text-cyan-200/25">
                    Run entity projection and risk scoring via <span class="mono-ui">EntityRiskScoringService::recalculateAll()</span>.
                </p>
            </div>
        @endif

    </div>
</x-app-layout>
