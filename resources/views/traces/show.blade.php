<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="brand-chip">Trace Investigation</p>
                <h2 class="mt-2 text-xl font-semibold leading-tight text-main-ui">Trace Timeline</h2>
                <p class="mono-ui mt-1 truncate text-xs text-cyan-200/55">{{ $traceId }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('traces.index') }}"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    ← Search
                </a>
                <a href="{{ route('api.traces.timeline', $traceId) }}" target="_blank" rel="noopener"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    JSON
                </a>
            </div>
        </div>
    </x-slot>

    <div class="flex gap-6">
        @include('traces._sidebar')

        <div class="min-w-0 flex-1 space-y-5">

            {{-- Summary metrics --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                @foreach ([
                    ['label' => 'Pipeline Events', 'value' => $summary['events_count']],
                    ['label' => 'Alerts', 'value' => $summary['alerts_count']],
                    ['label' => 'Incidents', 'value' => $summary['incidents_count']],
                    ['label' => 'Evidence', 'value' => $summary['evidence_count']],
                    ['label' => 'Top Severity', 'value' => strtoupper($summary['severity'])],
                ] as $metric)
                    <div class="glass-card px-4 py-3">
                        <p class="text-xs text-cyan-200/45">{{ $metric['label'] }}</p>
                        <p class="mt-1 text-lg font-semibold text-cyan-50">{{ $metric['value'] ?: '—' }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Pipeline flow indicator --}}
            <div class="glass-card p-5">
                <h3 class="mb-4 text-xs uppercase tracking-[0.12em] text-cyan-200/50">Pipeline Flow</h3>
                <div class="flex flex-wrap items-center gap-2">
                    @php
                        $services = $timeline->pluck('service')->unique()->flip();
                        $stages = [
                            'scenario-runner'         => 'Scenario Runner',
                            'ingestion-gateway'       => 'Ingestion Gateway',
                            'normalizer-worker'       => 'Normalizer',
                            'correlation-worker'      => 'Correlation',
                            'alert-writer-service'    => 'Alert Writer',
                            'incident-builder-service'=> 'Incident Builder',
                        ];
                        $topicStages = ['security_alerts' => 'Alert Writer', 'security_incidents' => 'Incident Builder'];
                    @endphp
                    @foreach ($stages as $svc => $label)
                        @php
                            $active = $services->has($svc)
                                || ($svc === 'alert-writer-service' && $alerts->isNotEmpty())
                                || ($svc === 'incident-builder-service' && $incidents->isNotEmpty());
                        @endphp
                        <div class="flex items-center gap-2">
                            <div class="rounded-lg border px-3 py-1.5 text-xs font-medium
                                {{ $active ? 'border-cyan-400/40 bg-cyan-500/15 text-cyan-200' : 'border-cyan-200/15 bg-black/20 text-cyan-200/30' }}">
                                @if ($active)
                                    <span class="mr-1.5 inline-block h-1.5 w-1.5 rounded-full bg-cyan-400"></span>
                                @endif
                                {{ $label }}
                            </div>
                            @if (!$loop->last)
                                <span class="text-cyan-200/25">→</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Timeline --}}
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3 flex items-center justify-between">
                    <span class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">Event Timeline</span>
                    <span class="text-xs text-cyan-200/35">{{ $timeline->count() }} events</span>
                </div>

                @if ($timeline->isEmpty())
                    <div class="p-8 text-center text-sm text-cyan-200/40">No pipeline events recorded for this trace.</div>
                @else
                    <div class="relative px-5 py-4">
                        <div class="absolute left-8 top-0 bottom-0 w-px bg-cyan-200/10"></div>
                        <div class="space-y-3">
                            @foreach ($timeline as $event)
                                @php
                                    $sourceColor = match($event->source ?? '') {
                                        'security_alerts'    => 'border-orange-400/40 bg-orange-500/10',
                                        'security_incidents' => 'border-red-400/40 bg-red-500/10',
                                        'scenario_evidence'  => 'border-purple-400/40 bg-purple-500/10',
                                        default              => 'border-cyan-400/30 bg-cyan-500/10',
                                    };
                                    $statusDot = match($event->status ?? 'ok') {
                                        'detected' => 'bg-green-400',
                                        'failed'   => 'bg-red-400',
                                        'partial'  => 'bg-yellow-400',
                                        default    => 'bg-cyan-400',
                                    };
                                @endphp
                                <div class="relative flex items-start gap-4">
                                    <div class="relative z-10 mt-1 flex h-4 w-4 shrink-0 items-center justify-center">
                                        <div class="h-2 w-2 rounded-full {{ $statusDot }}"></div>
                                    </div>
                                    <div class="flex-1 rounded-lg border {{ $sourceColor }} px-4 py-3 text-xs">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="mono-ui font-medium text-cyan-100">{{ $event->event_type }}</span>
                                            <span class="text-cyan-200/45">·</span>
                                            <span class="text-cyan-200/55">{{ $event->service }}</span>
                                            @if ($event->topic)
                                                <span class="text-cyan-200/45">·</span>
                                                <span class="mono-ui text-cyan-200/45">{{ $event->topic }}</span>
                                            @endif
                                            @if ($event->latency_ms)
                                                <span class="text-cyan-200/45">·</span>
                                                <span class="text-cyan-200/45">{{ $event->latency_ms }}ms</span>
                                            @endif
                                        </div>
                                        <div class="mt-1 text-cyan-200/45">
                                            {{ $event->occurred_at
                                                ? \Carbon\Carbon::parse($event->occurred_at)->format('Y-m-d H:i:s.v')
                                                : '—' }}
                                        </div>
                                        @if ($event->summary)
                                            <div class="mono-ui mt-1 text-cyan-200/35">{{ $event->summary }}</div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- Alerts tab --}}
            @if ($alerts->isNotEmpty())
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <span class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">Correlated Alerts ({{ $alerts->count() }})</span>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.08em] text-cyan-200/35">
                            <th class="px-5 py-2.5">Alert ID</th>
                            <th class="px-4 py-2.5">Type</th>
                            <th class="px-4 py-2.5">Severity</th>
                            <th class="px-4 py-2.5">Actor</th>
                            <th class="px-4 py-2.5">Detected</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($alerts as $alert)
                            @php
                                $sevColor = match($alert->severity) {
                                    'critical' => 'text-red-300 border-red-400/40 bg-red-500/10',
                                    'high'     => 'text-orange-300 border-orange-400/40 bg-orange-500/10',
                                    'medium'   => 'text-yellow-300 border-yellow-400/40 bg-yellow-500/10',
                                    default    => 'text-cyan-300 border-cyan-400/40 bg-cyan-500/10',
                                };
                            @endphp
                            <tr>
                                <td class="px-5 py-2.5"><span class="mono-ui text-xs text-cyan-200/70">{{ Str::limit($alert->alert_id, 24) }}</span></td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/70">{{ $alert->alert_type }}</td>
                                <td class="px-4 py-2.5"><span class="rounded border px-1.5 py-0.5 text-xs {{ $sevColor }}">{{ strtoupper($alert->severity) }}</span></td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/55">{{ $alert->actor_key ?? $alert->ip ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/45">{{ $alert->detected_at ? \Carbon\Carbon::parse($alert->detected_at)->format('H:i:s') : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            {{-- Incidents tab --}}
            @if ($incidents->isNotEmpty())
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <span class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">Linked Incidents ({{ $incidents->count() }})</span>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.08em] text-cyan-200/35">
                            <th class="px-5 py-2.5">Incident ID</th>
                            <th class="px-4 py-2.5">Title</th>
                            <th class="px-4 py-2.5">Severity</th>
                            <th class="px-4 py-2.5">Status</th>
                            <th class="px-4 py-2.5">First Seen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($incidents as $inc)
                            <tr>
                                <td class="px-5 py-2.5"><span class="mono-ui text-xs text-cyan-200/70">{{ Str::limit($inc->incident_id, 28) }}</span></td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/70">{{ $inc->title ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/55">{{ strtoupper($inc->severity) }}</td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/55">{{ $inc->status }}</td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/45">{{ $inc->first_seen_at ? \Carbon\Carbon::parse($inc->first_seen_at)->format('H:i:s') : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            {{-- Scenario evidence --}}
            @if ($evidence->isNotEmpty())
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <span class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">Scenario Evidence ({{ $evidence->count() }})</span>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.08em] text-cyan-200/35">
                            <th class="px-5 py-2.5">Stage</th>
                            <th class="px-4 py-2.5">Type</th>
                            <th class="px-4 py-2.5">Rule</th>
                            <th class="px-4 py-2.5">Status</th>
                            <th class="px-4 py-2.5">Latency</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($evidence as $ev)
                            @php
                                $statusColor = match($ev->status) {
                                    'detected' => 'text-green-300 border-green-400/40 bg-green-500/10',
                                    'failed'   => 'text-red-300 border-red-400/40 bg-red-500/10',
                                    default    => 'text-cyan-300 border-cyan-400/40 bg-cyan-500/10',
                                };
                            @endphp
                            <tr>
                                <td class="px-5 py-2.5 text-xs text-cyan-200/70">{{ $ev->stage }}</td>
                                <td class="px-4 py-2.5"><span class="mono-ui text-xs text-cyan-200/45">{{ $ev->stage_type }}</span></td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/55">{{ $ev->rule_id ?? '—' }}</td>
                                <td class="px-4 py-2.5"><span class="rounded border px-1.5 py-0.5 text-xs {{ $statusColor }}">{{ strtoupper($ev->status) }}</span></td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/45">{{ $ev->latency_ms !== null ? $ev->latency_ms.'ms' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            {{-- Scenario run context --}}
            @if ($scenarioRun)
            <div class="glass-card p-5">
                <h3 class="mb-3 text-xs uppercase tracking-[0.12em] text-cyan-200/50">Scenario Run Context</h3>
                <div class="grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
                    @foreach ([
                        'Scenario'    => $scenarioRun->scenario_id,
                        'Run Mode'    => $scenarioRun->run_mode,
                        'Status'      => $scenarioRun->status,
                        'Result'      => $scenarioRun->validation_result ?? '—',
                    ] as $label => $value)
                        <div>
                            <p class="text-cyan-200/40">{{ $label }}</p>
                            <p class="mt-0.5 font-medium text-cyan-100">{{ $value }}</p>
                        </div>
                    @endforeach
                </div>
                @if ($scenarioRun->id)
                    <div class="mt-3">
                        <a href="{{ route('scenario.runs.timeline', $scenarioRun->id) }}"
                           class="text-xs text-cyan-400 hover:text-cyan-200 transition">
                            View Scenario Timeline →
                        </a>
                    </div>
                @endif
            </div>
            @endif

        </div>
    </div>
</x-app-layout>
