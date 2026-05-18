<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="brand-chip">Entity Risk</p>
                <h2 class="mt-2 text-xl font-semibold leading-tight text-main-ui">Risk Factor Breakdown</h2>
                <p class="mono-ui mt-1 truncate text-xs text-cyan-200/55">
                    <span class="rounded border border-cyan-200/20 bg-cyan-100/5 px-1.5 py-0.5 mr-1.5">{{ $entity->entity_type }}</span>
                    {{ $entity->entity_key }}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('entity.risk-dashboard') }}"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    ← Dashboard
                </a>
                <a href="{{ route('entity.show', $entity->id) }}"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    Entity Detail
                </a>
                <a href="{{ route('api.entities.risk', $entity->id) }}" target="_blank" rel="noopener"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    JSON
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-5">

        {{-- Advisory banner --}}
        <div class="rounded-lg border border-yellow-400/20 bg-yellow-500/5 px-4 py-2.5 text-xs text-yellow-200/60">
            Risk assessment is advisory only. No automated actions are triggered by this score.
            Factors marked <span class="mono-ui">advisory_only</span> originate from shadow (non-active) detection domains.
        </div>

        {{-- Current risk summary --}}
        @php
            $riskLevel = $entity->risk_level ?? 'none';
            $riskScore = $entity->risk_score ?? 0.0;
            $lvlColor  = match($riskLevel) {
                'critical' => 'border-red-400/60 bg-red-500/10 text-red-200',
                'high'     => 'border-orange-400/60 bg-orange-500/10 text-orange-200',
                'medium'   => 'border-yellow-400/60 bg-yellow-500/10 text-yellow-200',
                'low'      => 'border-green-400/60 bg-green-500/10 text-green-200',
                default    => 'border-cyan-200/20 bg-black/20 text-cyan-200/40',
            };
        @endphp

        <div class="glass-card p-5">
            <div class="flex flex-wrap items-center gap-6">
                <div>
                    <p class="text-xs text-cyan-200/40 mb-2">Risk Level</p>
                    <span class="rounded-xl border-2 px-5 py-2 text-sm font-bold uppercase {{ $lvlColor }}">
                        {{ $riskLevel }}
                    </span>
                </div>
                <div>
                    <p class="text-xs text-cyan-200/40 mb-1">Risk Score</p>
                    <p class="text-3xl font-bold text-cyan-50 mono-ui">{{ number_format($riskScore, 1) }}<span class="text-lg text-cyan-200/40">/10</span></p>
                </div>
                @if ($entity->last_risk_calculated_at)
                    <div>
                        <p class="text-xs text-cyan-200/40 mb-1">Last Calculated</p>
                        <p class="text-xs text-cyan-200/55 mono-ui">{{ \Carbon\Carbon::parse($entity->last_risk_calculated_at)->format('Y-m-d H:i:s') }}</p>
                    </div>
                @endif
                <div>
                    <p class="text-xs text-cyan-200/40 mb-1">Factors Contributing</p>
                    <p class="text-xl font-semibold text-cyan-50 mono-ui">{{ count($factors) }}</p>
                </div>
            </div>

            {{-- Score bar --}}
            <div class="mt-5">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-xs text-cyan-200/40">0</span>
                    <span class="text-xs text-cyan-200/40">10</span>
                </div>
                <div class="h-3 w-full overflow-hidden rounded-full bg-cyan-200/10">
                    <div class="h-full rounded-full transition-all {{ match($riskLevel) {
                        'critical' => 'bg-red-500',
                        'high'     => 'bg-orange-500',
                        'medium'   => 'bg-yellow-500',
                        default    => 'bg-green-500',
                    } }}"
                         style="width: {{ min(number_format($riskScore * 10, 0), 100) }}%">
                    </div>
                </div>
                <div class="mt-1 flex text-[10px] text-cyan-200/25">
                    <span style="width:25%">LOW</span>
                    <span style="width:25%">MEDIUM</span>
                    <span style="width:25%">HIGH</span>
                    <span style="width:25%" class="text-right">CRITICAL</span>
                </div>
            </div>
        </div>

        {{-- Factor breakdown table --}}
        @if (!empty($factors))
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                        Contributing Factors — Explainability
                    </h3>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.09em] text-cyan-200/40">
                            <th class="px-5 py-3">Factor</th>
                            <th class="px-4 py-3 text-center">Value</th>
                            <th class="px-4 py-3 text-center">Weight</th>
                            <th class="px-4 py-3 text-center">Contribution</th>
                            <th class="px-4 py-3">Evidence</th>
                            <th class="px-4 py-3">Note</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($factors as $f)
                            <tr class="hover:bg-cyan-100/3 transition">
                                <td class="px-5 py-3">
                                    <span class="mono-ui text-xs text-cyan-200">{{ $f['factor'] }}</span>
                                </td>
                                <td class="px-4 py-3 text-center text-xs text-cyan-200/70 mono-ui">
                                    {{ $f['value'] }}
                                </td>
                                <td class="px-4 py-3 text-center text-xs text-cyan-200/55 mono-ui">
                                    × {{ number_format($f['weight'], 1) }}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="mono-ui text-xs font-semibold text-cyan-50">
                                        {{ number_format($f['contribution'], 2) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    @if (!empty($f['alert_ids']))
                                        <span class="text-xs text-cyan-200/50">
                                            {{ count($f['alert_ids']) }} alert(s)
                                            <span class="mono-ui text-cyan-200/30 ml-1">{{ Str::limit(implode(', ', array_slice($f['alert_ids'], 0, 2)), 36) }}</span>
                                        </span>
                                    @elseif (!empty($f['incident_ids']))
                                        <span class="text-xs text-cyan-200/50">
                                            {{ count($f['incident_ids']) }} incident(s)
                                        </span>
                                    @elseif (!empty($f['trace_ids']))
                                        <span class="text-xs text-cyan-200/50">
                                            {{ count($f['trace_ids']) }} trace(s)
                                        </span>
                                    @else
                                        <span class="text-xs text-cyan-200/25">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if (!empty($f['advisory_only']))
                                        <span class="rounded border border-yellow-400/30 bg-yellow-500/5 px-1.5 py-0.5 text-[10px] text-yellow-300/70 mono-ui">
                                            advisory_only
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-cyan-400/20 bg-cyan-500/5">
                            <td class="px-5 py-3 text-xs font-semibold text-cyan-200/70" colspan="3">Total Risk Score</td>
                            <td class="px-4 py-3 text-center">
                                <span class="mono-ui text-sm font-bold text-cyan-50">{{ number_format($riskScore, 2) }}</span>
                                <span class="text-xs text-cyan-200/30"> / 10.00</span>
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @else
            <div class="glass-card p-6 text-center text-sm text-cyan-200/40">
                No risk factors calculated for this entity yet.
            </div>
        @endif

        {{-- Risk history (trend view) --}}
        @if ($history->isNotEmpty())
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                        Risk History — Trend ({{ $history->count() }} snapshots)
                    </h3>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.09em] text-cyan-200/40">
                            <th class="px-5 py-3">Calculated At</th>
                            <th class="px-4 py-3 text-center">Score</th>
                            <th class="px-4 py-3">Level</th>
                            <th class="px-4 py-3 text-center">Factors</th>
                            <th class="px-4 py-3 text-center">Alerts</th>
                            <th class="px-4 py-3 text-center">Incidents</th>
                            <th class="px-4 py-3 text-center">Traces</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($history as $snap)
                            @php
                                $snapFactors  = is_string($snap->risk_factors)  ? json_decode($snap->risk_factors, true)  : ($snap->risk_factors  ?? []);
                                $snapAlerts   = is_string($snap->alert_ids)     ? json_decode($snap->alert_ids, true)     : ($snap->alert_ids     ?? []);
                                $snapIncidents= is_string($snap->incident_ids)  ? json_decode($snap->incident_ids, true)  : ($snap->incident_ids  ?? []);
                                $snapTraces   = is_string($snap->trace_ids)     ? json_decode($snap->trace_ids, true)     : ($snap->trace_ids     ?? []);
                                $snapLvlColor = match($snap->risk_level) {
                                    'critical' => 'text-red-300 border-red-400/40 bg-red-500/10',
                                    'high'     => 'text-orange-300 border-orange-400/40 bg-orange-500/10',
                                    'medium'   => 'text-yellow-300 border-yellow-400/40 bg-yellow-500/10',
                                    default    => 'text-green-300 border-green-400/40 bg-green-500/10',
                                };
                            @endphp
                            <tr class="hover:bg-cyan-100/3 transition">
                                <td class="px-5 py-2.5 text-xs text-cyan-200/60 mono-ui">
                                    {{ \Carbon\Carbon::parse($snap->calculated_at)->format('Y-m-d H:i:s') }}
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <span class="mono-ui text-xs font-bold text-cyan-50">{{ number_format($snap->risk_score, 1) }}</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded border px-1.5 py-0.5 text-xs {{ $snapLvlColor }}">
                                        {{ strtoupper($snap->risk_level) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-center text-xs text-cyan-200/55">{{ count($snapFactors) }}</td>
                                <td class="px-4 py-2.5 text-center text-xs text-cyan-200/55">{{ count($snapAlerts) }}</td>
                                <td class="px-4 py-2.5 text-center text-xs text-cyan-200/55">{{ count($snapIncidents) }}</td>
                                <td class="px-4 py-2.5 text-center text-xs text-cyan-200/55">{{ count($snapTraces) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

    </div>
</x-app-layout>
