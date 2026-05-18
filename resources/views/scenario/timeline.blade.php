<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="brand-chip">Live Validation Timeline</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">{{ $scenario['title'] }}</h2>
                <p class="mt-1 font-mono text-xs text-cyan-200/55">
                    Run #{{ $run->id }} &middot; {{ $run->trace_id }} &middot; {{ $run->run_mode }}
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <span class="rounded border px-2 py-1 text-sm {{ $run->statusBadgeClass() }}">{{ $run->status }}</span>
                @if ($run->validation_result)
                    <span class="rounded border px-2 py-1 text-sm {{ $run->validationBadgeClass() }}">{{ $run->validation_result }}</span>
                @endif

                @can('soc:scenario.evidence.view')
                <a href="{{ route('scenario.runs.evidence', $run->id) }}"
                   class="rounded-lg border border-cyan-200/25 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200 hover:bg-cyan-100/15 transition">
                    Evidence
                </a>
                @endcan

                @if ($run->validation_result)
                <a href="{{ route('scenario.runs.report', $run->id) }}"
                   class="rounded-lg border border-cyan-200/25 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200 hover:bg-cyan-100/15 transition">
                    Report
                </a>
                @endif

                @if (in_array($run->status, ['pending', 'running'], true))
                    @can('soc:scenario.run')
                    <form method="POST" action="{{ route('scenario.runs.stop', $run->id) }}">
                        @csrf
                        <button type="submit"
                                class="rounded-lg border border-red-400/40 bg-red-500/15 px-3 py-1.5 text-sm text-red-200 hover:bg-red-500/25 transition">
                            ■ Stop Run
                        </button>
                    </form>
                    @endcan
                @endif

                @can('soc:scenario.export')
                <a href="{{ route('scenario.reports.export', $run->id) }}"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    ↓ Export
                </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="flex gap-6">
        @include('scenario._sidebar')

        <div class="min-w-0 flex-1 space-y-4">
            {{-- Summary metrics --}}
            @php
                $totalLatencyMs = $run->evidence->sum('latency_ms');
                $results = $run->results ?? [];
            @endphp
            <div class="grid gap-3 sm:grid-cols-5">
                <div class="metric-card">
                    <p class="text-xs uppercase tracking-[0.14em] text-cyan-200/70">Detection</p>
                    <p class="mt-2 text-2xl font-semibold">
                        @if ($run->detection_passed === true)
                            <span class="text-green-300">PASS</span>
                        @elseif ($run->detection_passed === false)
                            <span class="text-red-300">FAIL</span>
                        @else
                            <span class="text-cyan-100/40">—</span>
                        @endif
                    </p>
                </div>
                <div class="metric-card">
                    <p class="text-xs uppercase tracking-[0.14em] text-cyan-200/70">Alerts</p>
                    <p class="mt-2 text-3xl font-semibold text-main-ui">{{ $run->alerts_detected }}</p>
                </div>
                <div class="metric-card">
                    <p class="text-xs uppercase tracking-[0.14em] text-cyan-200/70">Stages</p>
                    <p class="mt-2 text-3xl font-semibold text-main-ui">{{ $run->evidence->count() }}/{{ count($scenario['pipeline_stages']) }}</p>
                </div>
                <div class="metric-card">
                    <p class="text-xs uppercase tracking-[0.14em] text-cyan-200/70">Pipeline Latency</p>
                    <p class="mt-2 text-2xl font-semibold text-main-ui">
                        @if ($totalLatencyMs)
                            {{ $totalLatencyMs }}<span class="text-sm text-cyan-100/50">ms</span>
                        @else
                            —
                        @endif
                    </p>
                </div>
                <div class="metric-card">
                    <p class="text-xs uppercase tracking-[0.14em] text-cyan-200/70">Rule Matched</p>
                    <p class="mono-ui mt-2 truncate text-xs text-cyan-200">{{ $results['rule_matched'] ?? '—' }}</p>
                </div>
            </div>

            {{-- Pipeline stage trace --}}
            <div class="glass-card p-5">
                <h3 class="mb-5 text-base font-semibold text-main-ui">Pipeline Stage Trace</h3>

                <div class="space-y-2">
                    @foreach ($scenario['pipeline_stages'] as $i => $stage)
                        @php
                            $stageEvidence = $evidenceByStage->get($stage, collect());
                            $hasEvidence   = $stageEvidence->isNotEmpty();
                            $detected      = $stageEvidence->where('status', 'detected')->isNotEmpty();
                            $failed        = $stageEvidence->where('status', 'failed')->isNotEmpty();
                            $processing    = $stageEvidence->where('status', 'processing')->isNotEmpty();
                            $stageLatency  = $stageEvidence->sum('latency_ms');

                            if ($failed) {
                                $stageColor = 'border-red-400/40 bg-red-500/10';
                                $dotColor   = 'bg-red-400';
                                $icon       = '✗';
                                $iconColor  = 'text-red-300';
                                $statusLabel = 'failed';
                                $statusLabelColor = 'text-red-300/80';
                            } elseif ($detected) {
                                $stageColor = 'border-green-400/30 bg-green-500/8';
                                $dotColor   = 'bg-green-400';
                                $icon       = '✓';
                                $iconColor  = 'text-green-300';
                                $statusLabel = 'completed';
                                $statusLabelColor = 'text-green-300/80';
                            } elseif ($processing) {
                                $stageColor = 'border-cyan-400/30 bg-cyan-500/10';
                                $dotColor   = 'bg-cyan-400 animate-pulse';
                                $icon       = '⟳';
                                $iconColor  = 'text-cyan-300';
                                $statusLabel = 'processing';
                                $statusLabelColor = 'text-cyan-300/80';
                            } elseif ($run->status === 'running' || $run->status === 'pending') {
                                $stageColor = 'border-cyan-200/15 bg-black/10';
                                $dotColor   = 'bg-cyan-900 animate-pulse';
                                $icon       = '·';
                                $iconColor  = 'text-cyan-100/30';
                                $statusLabel = 'pending';
                                $statusLabelColor = 'text-cyan-100/30';
                            } else {
                                $stageColor = 'border-cyan-200/10 bg-black/10';
                                $dotColor   = 'bg-cyan-900';
                                $icon       = '·';
                                $iconColor  = 'text-cyan-100/25';
                                $statusLabel = 'skipped';
                                $statusLabelColor = 'text-cyan-100/25';
                            }
                        @endphp

                        <div class="flex items-start gap-3">
                            <div class="flex flex-col items-center">
                                <div class="mt-0.5 h-3.5 w-3.5 shrink-0 rounded-full {{ $dotColor }}"></div>
                                @if (!$loop->last)
                                    <div class="mt-0.5 w-px flex-1 bg-cyan-200/12" style="min-height: 1.25rem"></div>
                                @endif
                            </div>

                            <div class="mb-1.5 flex-1">
                                <div class="rounded-lg border px-4 py-2.5 {{ $stageColor }}">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs text-cyan-100/30">{{ $i + 1 }}</span>
                                            <span class="mono-ui text-sm text-cyan-200">{{ $stage }}</span>
                                            <span class="text-xs {{ $statusLabelColor }}">{{ $statusLabel }}</span>
                                            @php $stageTypeLabel = $stageEvidence->first()?->stage_type; @endphp
                                            @if ($stageTypeLabel === 'real_pipeline_stage')
                                                <span class="rounded border border-green-400/30 bg-green-500/10 px-1.5 py-0.5 text-xs text-green-300">real</span>
                                            @elseif ($stageTypeLabel === 'simulated_runner_stage')
                                                <span class="rounded border border-cyan-200/20 bg-cyan-100/5 px-1.5 py-0.5 text-xs text-cyan-100/50">sim</span>
                                            @endif
                                        </div>
                                        <div class="flex shrink-0 items-center gap-3">
                                            @if ($stageLatency > 0)
                                                <span class="mono-ui text-xs text-cyan-100/45">{{ $stageLatency }}ms</span>
                                            @endif
                                            <span class="{{ $iconColor }} font-bold">{{ $icon }}</span>
                                        </div>
                                    </div>

                                    @if ($stageEvidence->isNotEmpty())
                                        <div class="mt-2 space-y-1.5 border-t border-cyan-200/10 pt-2">
                                            @foreach ($stageEvidence as $ev)
                                                <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-cyan-100/65">
                                                    @if ($ev->event_id)
                                                        <span class="mono-ui truncate text-cyan-100/60">{{ $ev->event_id }}</span>
                                                    @endif
                                                    @if ($ev->rule_id)
                                                        <span class="rounded border border-cyan-300/30 bg-cyan-500/10 px-1.5 py-0.5 text-cyan-200">{{ $ev->rule_id }}</span>
                                                    @endif
                                                    @if ($ev->severity)
                                                        @php
                                                            $sColor = match($ev->severity) {
                                                                'critical' => 'text-red-300',
                                                                'high'     => 'text-orange-300',
                                                                'medium'   => 'text-yellow-300',
                                                                default    => 'text-cyan-300',
                                                            };
                                                        @endphp
                                                        <span class="{{ $sColor }}">{{ strtoupper($ev->severity) }}</span>
                                                    @endif
                                                    @if ($ev->processed_at)
                                                        <span class="ml-auto text-cyan-100/35">{{ $ev->processed_at->format('H:i:s.v') }}</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Failure / recommendation panel --}}
            @if ($run->failure_reason || $run->recommendation)
                <div class="glass-card p-5">
                    @if ($run->failure_reason)
                        <div class="mb-4">
                            <p class="text-xs uppercase tracking-[0.12em] text-red-300/75">Failure Reason</p>
                            <p class="mono-ui mt-1.5 text-sm text-red-200">{{ $run->failure_reason }}</p>
                        </div>
                    @endif
                    @if ($run->recommendation)
                        <div>
                            <p class="text-xs uppercase tracking-[0.12em] text-cyan-100/55">Recommendation</p>
                            <p class="mt-1.5 text-sm text-cyan-100/80">{{ $run->recommendation }}</p>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Run metadata --}}
            @if ($results)
                <div class="glass-card p-5">
                    <h3 class="mb-3 text-sm font-semibold text-main-ui">Run Metadata</h3>
                    <div class="grid gap-x-6 gap-y-2 text-xs sm:grid-cols-3">
                        @foreach (['alert_id', 'incident_id', 'event_id', 'rule_matched', 'confidence', 'engine', 'scope', 'run_type'] as $key)
                            @if (isset($results[$key]))
                                <div>
                                    <span class="text-cyan-100/45">{{ $key }}</span>
                                    <p class="mono-ui mt-0.5 truncate text-cyan-200">{{ $results[$key] }}</p>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if (in_array($run->status, ['pending', 'running'], true))
        <script>setTimeout(() => window.location.reload(), 2000);</script>
    @endif
</x-app-layout>
