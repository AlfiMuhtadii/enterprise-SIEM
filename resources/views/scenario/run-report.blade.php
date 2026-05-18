<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="brand-chip">Validation Report</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">{{ $scenario['title'] }}</h2>
                <p class="mt-1 font-mono text-xs text-cyan-200/55">
                    Run #{{ $run->id }} &middot; {{ $run->trace_id }}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('scenario.runs.timeline', $run->id) }}"
                   class="rounded-lg border border-cyan-200/25 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200 hover:bg-cyan-100/15 transition">
                    ← Timeline
                </a>
                @can('soc:scenario.evidence.view')
                <a href="{{ route('scenario.runs.evidence', $run->id) }}"
                   class="rounded-lg border border-cyan-200/25 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200 hover:bg-cyan-100/15 transition">
                    Evidence
                </a>
                @endcan
                @can('soc:scenario.export')
                <a href="{{ route('scenario.reports.export', $run->id) }}"
                   class="rounded-lg border border-cyan-200/25 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200 hover:bg-cyan-100/15 transition">
                    ↓ Export JSON
                </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="flex gap-6">
        @include('scenario._sidebar')

        <div class="min-w-0 flex-1 space-y-4">

            {{-- Result banner --}}
            @php
                $bannerConfig = match($run->validation_result) {
                    'PASS'    => ['bg-green-500/10 border-green-400/30',    'text-green-200', 'text-green-300',  '✓ PASS'],
                    'PARTIAL' => ['bg-yellow-500/10 border-yellow-400/30',  'text-yellow-200','text-yellow-300', '⚠ PARTIAL'],
                    'FAIL'    => ['bg-red-500/10 border-red-400/40',        'text-red-200',   'text-red-300',    '✗ FAIL'],
                    default   => ['bg-black/20 border-cyan-200/15',         'text-cyan-200',  'text-cyan-300',   '— PENDING'],
                };
            @endphp
            <div class="rounded-xl border {{ $bannerConfig[0] }} p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-[0.14em] {{ $bannerConfig[1] }}/70">Validation Result</p>
                        <p class="mt-1 text-4xl font-bold {{ $bannerConfig[2] }}">{{ $bannerConfig[3] }}</p>
                    </div>
                    <div class="text-right text-sm {{ $bannerConfig[1] }}/70 space-y-1">
                        <p>Stages: <span class="font-semibold text-white">{{ $passedStages }}/{{ $run->evidence->count() }} passed</span></p>
                        @if ($failedStages > 0)
                            <p class="text-red-300">{{ $failedStages }} stage(s) failed</p>
                        @endif
                        <p>Pipeline latency: <span class="mono-ui font-semibold text-white">{{ $totalLatencyMs }}ms</span></p>
                    </div>
                </div>
            </div>

            {{-- Detection outcome --}}
            <div class="glass-card p-5">
                <h3 class="mb-4 text-base font-semibold text-main-ui">Detection Outcome</h3>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                        <p class="text-xs text-cyan-100/50">Rule Matched</p>
                        <p class="mono-ui mt-1 text-sm text-cyan-200">{{ $results['rule_matched'] ?? $scenario['expected_detection']['rule'] }}</p>
                    </div>
                    <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                        <p class="text-xs text-cyan-100/50">Confidence</p>
                        <p class="mono-ui mt-1 text-sm text-cyan-200">{{ $results['confidence'] ?? $scenario['expected_detection']['confidence'] }}</p>
                    </div>
                    <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                        <p class="text-xs text-cyan-100/50">Severity</p>
                        @php
                            $sev = $results['severity'] ?? $scenario['expected_detection']['severity'] ?? 'medium';
                            $sevColor = match($sev) {
                                'critical' => 'text-red-300', 'high' => 'text-orange-300',
                                'medium'   => 'text-yellow-300', default => 'text-cyan-300',
                            };
                        @endphp
                        <p class="mono-ui mt-1 text-sm {{ $sevColor }}">{{ strtoupper($sev) }}</p>
                    </div>
                    <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                        <p class="text-xs text-cyan-100/50">Alerts Created</p>
                        <p class="mono-ui mt-1 text-sm text-cyan-200">{{ $run->alerts_detected }}</p>
                    </div>
                </div>

                @if (isset($results['alert_id']) || isset($results['incident_id']))
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        @if (isset($results['alert_id']))
                        <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                            <p class="text-xs text-cyan-100/50">Alert ID</p>
                            <p class="mono-ui mt-1 truncate text-xs text-cyan-200">{{ $results['alert_id'] }}</p>
                        </div>
                        @endif
                        @if (isset($results['incident_id']))
                        <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                            <p class="text-xs text-cyan-100/50">Incident ID</p>
                            <p class="mono-ui mt-1 truncate text-xs text-cyan-200">{{ $results['incident_id'] }}</p>
                        </div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Weakness / Failure analysis --}}
            @if ($run->validation_result !== 'PASS' || $run->failure_reason)
                <div class="glass-card p-5">
                    <h3 class="mb-4 text-base font-semibold text-main-ui">Weakness Analysis</h3>

                    @if ($run->failure_reason)
                        <div class="mb-4 rounded-lg border border-red-400/30 bg-red-500/10 p-4">
                            <p class="text-xs uppercase tracking-[0.12em] text-red-300/70">Failure Reason</p>
                            <p class="mono-ui mt-2 text-sm text-red-200">{{ $run->failure_reason }}</p>
                        </div>
                    @endif

                    @if ($failedStages > 0)
                        <div class="space-y-2">
                            <p class="text-sm font-medium text-main-ui">Failed Stages</p>
                            @foreach ($evidenceByStage as $stage => $items)
                                @if ($items->where('status', 'failed')->isNotEmpty())
                                    <div class="rounded-lg border border-red-400/25 bg-red-500/8 px-4 py-2.5">
                                        <span class="mono-ui text-sm text-red-200">{{ $stage }}</span>
                                        <span class="ml-2 text-xs text-red-300/70">— detection failed at this stage</span>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            {{-- Impact --}}
            <div class="glass-card p-5">
                <h3 class="mb-3 text-base font-semibold text-main-ui">Business Impact</h3>
                <p class="text-sm text-cyan-100/75">{{ $impact }}</p>
                <div class="mt-3 text-sm text-cyan-100/65">
                    <strong class="text-cyan-200">MITRE:</strong>
                    {{ $scenario['mitre_id'] }} — {{ $scenario['mitre_name'] }}
                </div>
            </div>

            {{-- Recommendation --}}
            @if ($run->recommendation)
                <div class="glass-card p-5">
                    <h3 class="mb-3 text-base font-semibold text-main-ui">Recommendation</h3>
                    <p class="text-sm text-cyan-100/80">{{ $run->recommendation }}</p>
                </div>
            @endif

            {{-- Evidence trail --}}
            <div class="glass-card p-5">
                <h3 class="mb-4 text-base font-semibold text-main-ui">Evidence Trail</h3>
                @if ($run->evidence->isEmpty())
                    <p class="text-sm text-cyan-100/45">No evidence records for this run.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-cyan-200/15 text-left text-xs uppercase tracking-[0.12em] text-cyan-100/50">
                                    <th class="pb-2 pr-4">Stage</th>
                                    <th class="pb-2 pr-4">Status</th>
                                    <th class="pb-2 pr-4">Rule</th>
                                    <th class="pb-2 pr-4">Severity</th>
                                    <th class="pb-2 pr-4">Latency</th>
                                    <th class="pb-2">Timestamp</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-cyan-200/10">
                                @foreach ($run->evidence->sortBy('processed_at') as $ev)
                                    @php
                                        $rowSc = match($ev->status) {
                                            'detected'   => 'text-green-300',
                                            'failed'     => 'text-red-300',
                                            'processing' => 'text-cyan-300',
                                            default      => 'text-cyan-100/40',
                                        };
                                    @endphp
                                    <tr class="text-cyan-100/75 hover:bg-cyan-100/5">
                                        <td class="mono-ui py-2.5 pr-4 text-xs text-cyan-200">{{ $ev->stage }}</td>
                                        <td class="py-2.5 pr-4">
                                            <span class="text-xs {{ $rowSc }}">{{ $ev->status }}</span>
                                        </td>
                                        <td class="mono-ui py-2.5 pr-4 text-xs">{{ $ev->rule_id ?? '—' }}</td>
                                        <td class="py-2.5 pr-4 text-xs">{{ $ev->severity ?? '—' }}</td>
                                        <td class="mono-ui py-2.5 pr-4 text-xs text-cyan-100/55">
                                            {{ $ev->latency_ms ? $ev->latency_ms . 'ms' : '—' }}
                                        </td>
                                        <td class="mono-ui py-2.5 text-xs text-cyan-100/45">
                                            {{ $ev->processed_at?->format('H:i:s.v') ?? '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Run metadata --}}
            <div class="glass-card p-5">
                <h3 class="mb-3 text-base font-semibold text-main-ui">Run Metadata</h3>
                <div class="grid gap-3 text-xs sm:grid-cols-2 lg:grid-cols-3">
                    <div><span class="text-cyan-100/45">trace_id</span><p class="mono-ui mt-0.5 truncate text-cyan-200">{{ $run->trace_id }}</p></div>
                    <div><span class="text-cyan-100/45">run_mode</span><p class="mono-ui mt-0.5 text-cyan-200">{{ $run->run_mode }}</p></div>
                    <div><span class="text-cyan-100/45">started_at</span><p class="mono-ui mt-0.5 text-cyan-200">{{ $run->started_at?->toIso8601String() ?? '—' }}</p></div>
                    <div><span class="text-cyan-100/45">completed_at</span><p class="mono-ui mt-0.5 text-cyan-200">{{ $run->completed_at?->toIso8601String() ?? '—' }}</p></div>
                    <div><span class="text-cyan-100/45">engine</span><p class="mono-ui mt-0.5 text-cyan-200">{{ $results['engine'] ?? '—' }}</p></div>
                    <div><span class="text-cyan-100/45">scope</span><p class="mono-ui mt-0.5 text-cyan-200">{{ $results['scope'] ?? '—' }}</p></div>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
