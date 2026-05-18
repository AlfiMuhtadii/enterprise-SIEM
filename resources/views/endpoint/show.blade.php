<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="brand-chip">Endpoint Timeline</p>
                <h2 class="mt-2 text-xl font-semibold leading-tight text-main-ui">Agent Detail</h2>
                <p class="mono-ui mt-1 text-xs text-cyan-200/55">{{ $agent->agent_id }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                @php
                    $sc = $agent->status === 'online'
                        ? 'border-green-400/40 bg-green-500/10 text-green-200'
                        : 'border-red-400/30 bg-red-500/10 text-red-200/60';
                @endphp
                <span class="rounded border px-2 py-1 text-sm {{ $sc }}">{{ $agent->status }}</span>
                <a href="{{ route('endpoint.index') }}"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">← Inventory</a>
            </div>
        </div>
    </x-slot>

    <div class="flex gap-6">
        @include('endpoint._sidebar')

        <div class="min-w-0 flex-1 space-y-5">

            {{-- Summary metrics --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ([
                    ['label' => 'Shadow Alerts',    'value' => $stats['alert_count'],     'color' => 'text-yellow-300'],
                    ['label' => 'Critical Alerts',  'value' => $stats['critical_alerts'],  'color' => 'text-red-300'],
                    ['label' => 'Rules Fired',      'value' => $stats['rules_fired'],      'color' => 'text-cyan-50'],
                    ['label' => 'Pipeline Events',  'value' => $stats['pipeline_events'],  'color' => 'text-cyan-200/70'],
                ] as $m)
                    <div class="glass-card px-4 py-3">
                        <p class="text-xs text-cyan-200/45">{{ $m['label'] }}</p>
                        <p class="mt-1 text-xl font-semibold {{ $m['color'] }}">{{ $m['value'] }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Agent metadata --}}
            <div class="glass-card p-5">
                <h3 class="mb-4 text-xs uppercase tracking-[0.12em] text-cyan-200/50">Agent Info</h3>
                <div class="grid grid-cols-2 gap-4 text-xs sm:grid-cols-4">
                    @foreach ([
                        'Host ID'     => $agent->host_id,
                        'Version'     => $agent->agent_version ?? '—',
                        'Platform'    => $agent->platform ?? '—',
                        'Policy'      => $policy?->name ?? $agent->policy_id ?? 'default',
                        'Enrolled'    => $agent->enrolled_at ? \Carbon\Carbon::parse($agent->enrolled_at)->format('Y-m-d') : '—',
                        'Last Seen'   => $agent->last_seen_at ? \Carbon\Carbon::parse($agent->last_seen_at)->format('Y-m-d H:i:s') : '—',
                        'Integrity'   => $agent->integrity ? '✓ ok' : '✗ failed',
                        'Restart Ct.' => $agent->metadata ? (json_decode($agent->metadata, true)['unexpected_restart_count'] ?? 0) : 0,
                    ] as $label => $value)
                        <div>
                            <p class="text-cyan-200/40">{{ $label }}</p>
                            <p class="mono-ui mt-0.5 font-medium {{ $label === 'Integrity' && !$agent->integrity ? 'text-red-300' : 'text-cyan-100' }}">{{ $value }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Shadow alert rule breakdown --}}
            @if ($byRule->isNotEmpty())
            <div class="glass-card p-5">
                <h3 class="mb-3 text-xs uppercase tracking-[0.12em] text-cyan-200/50">Shadow Detection — Rules Fired</h3>
                <div class="flex flex-wrap gap-2">
                    @foreach ($byRule as $rule => $info)
                        @php
                            $sc = match($info['severity']) {
                                'critical' => 'border-red-400/40 bg-red-500/10 text-red-200',
                                'high'     => 'border-orange-400/40 bg-orange-500/10 text-orange-200',
                                'medium'   => 'border-yellow-400/40 bg-yellow-500/10 text-yellow-200',
                                default    => 'border-cyan-400/30 bg-cyan-500/10 text-cyan-200',
                            };
                        @endphp
                        <div class="rounded-lg border {{ $sc }} px-3 py-1.5 text-xs">
                            <span class="mono-ui">{{ $rule }}</span>
                            <span class="ml-2 opacity-70">×{{ $info['count'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Shadow alert timeline --}}
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3 flex items-center justify-between">
                    <span class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                        Shadow Alerts ({{ $alerts->count() }})
                    </span>
                    <span class="text-xs text-orange-200/60">Shadow-only — never enters active incident pipeline</span>
                </div>
                @if ($alerts->isEmpty())
                    <div class="p-6 text-center text-sm text-cyan-200/40">No shadow alerts yet for this agent.</div>
                @else
                    <div class="relative px-5 py-4">
                        <div class="absolute left-8 top-0 bottom-0 w-px bg-cyan-200/10"></div>
                        <div class="space-y-3">
                            @foreach ($alerts as $alert)
                                @php
                                    $sevColor = match($alert->severity ?? 'medium') {
                                        'critical' => 'border-red-400/40 bg-red-500/10',
                                        'high'     => 'border-orange-400/40 bg-orange-500/10',
                                        'medium'   => 'border-yellow-400/40 bg-yellow-500/10',
                                        default    => 'border-cyan-400/30 bg-cyan-500/10',
                                    };
                                    $dot = match($alert->severity ?? 'medium') {
                                        'critical' => 'bg-red-400',
                                        'high'     => 'bg-orange-400',
                                        'medium'   => 'bg-yellow-400',
                                        default    => 'bg-cyan-400',
                                    };
                                @endphp
                                <div class="relative flex items-start gap-4">
                                    <div class="relative z-10 mt-1 flex h-4 w-4 shrink-0 items-center justify-center">
                                        <div class="h-2 w-2 rounded-full {{ $dot }}"></div>
                                    </div>
                                    <div class="flex-1 rounded-lg border {{ $sevColor }} px-4 py-3 text-xs">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="mono-ui font-medium text-cyan-100">{{ $alert->alert_type }}</span>
                                            <span class="rounded border px-1.5 py-0.5 text-xs {{ $sevColor }}">{{ strtoupper($alert->severity ?? 'medium') }}</span>
                                            @if ($alert->trace_id)
                                                <a href="{{ route('traces.show', $alert->trace_id) }}"
                                                   class="mono-ui text-cyan-400 hover:text-cyan-200 transition">trace →</a>
                                            @endif
                                        </div>
                                        <div class="mt-1 text-cyan-200/45">
                                            {{ $alert->detected_at ? \Carbon\Carbon::parse($alert->detected_at)->format('Y-m-d H:i:s') : '—' }}
                                        </div>
                                        @if ($alert->actor_key || $alert->ip)
                                            <div class="mono-ui mt-1 text-cyan-200/35">{{ $alert->actor_key ?? $alert->ip }}</div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- Pipeline events --}}
            @if ($pipelineEvents->isNotEmpty())
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <span class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">Pipeline Events ({{ $pipelineEvents->count() }})</span>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.08em] text-cyan-200/35">
                            <th class="px-5 py-2.5">Event Type</th>
                            <th class="px-4 py-2.5">Service</th>
                            <th class="px-4 py-2.5">Topic</th>
                            <th class="px-4 py-2.5">Occurred</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($pipelineEvents->take(30) as $ev)
                            <tr>
                                <td class="px-5 py-2 mono-ui text-xs text-cyan-200/70">{{ $ev->event_type }}</td>
                                <td class="px-4 py-2 text-xs text-cyan-200/55">{{ $ev->source_service }}</td>
                                <td class="px-4 py-2 mono-ui text-xs text-cyan-200/40">{{ $ev->source_topic }}</td>
                                <td class="px-4 py-2 text-xs text-cyan-200/45">
                                    {{ $ev->occurred_at ? \Carbon\Carbon::parse($ev->occurred_at)->format('H:i:s') : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            {{-- Queued commands --}}
            @if ($commands->isNotEmpty())
            <div class="glass-card p-5">
                <h3 class="mb-3 text-xs uppercase tracking-[0.12em] text-cyan-200/50">Queued Commands</h3>
                <div class="space-y-2">
                    @foreach ($commands as $cmd)
                        <div class="flex items-center gap-3 text-xs">
                            <span class="mono-ui text-cyan-200/60">{{ $cmd->command_type }}</span>
                            <span class="text-cyan-200/35">{{ $cmd->status }}</span>
                            <span class="ml-auto text-cyan-200/35">{{ $cmd->queued_at ? \Carbon\Carbon::parse($cmd->queued_at)->format('Y-m-d H:i') : '—' }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
            @endif

        </div>
    </div>
</x-app-layout>
