<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="brand-chip">XDR Scenario Runner</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">{{ $scenario['title'] }}</h2>
                <p class="mt-1 font-mono text-sm text-cyan-200/55">{{ $scenario['mitre_id'] }} — {{ $scenario['mitre_name'] }}</p>
            </div>
            {{-- Action panel --}}
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @can('soc:scenario.run')
                <form method="POST" action="{{ route('scenario.runs.store') }}">
                    @csrf
                    <input type="hidden" name="scenario_id" value="{{ $scenario['id'] }}">
                    <input type="hidden" name="run_mode" value="live">
                    <button type="submit"
                            class="rounded-lg border border-cyan-400/50 bg-cyan-500/20 px-4 py-2 text-sm font-semibold text-cyan-200 hover:bg-cyan-500/35 transition">
                        ▶ Run Scenario
                    </button>
                </form>
                @endcan

                @can('soc:scenario.replay')
                <form method="POST" action="{{ route('scenario.runs.store') }}">
                    @csrf
                    <input type="hidden" name="scenario_id" value="{{ $scenario['id'] }}">
                    <input type="hidden" name="run_mode" value="replay">
                    <button type="submit"
                            class="rounded-lg border border-purple-400/40 bg-purple-500/15 px-4 py-2 text-sm font-medium text-purple-200 hover:bg-purple-500/25 transition">
                        ↺ Replay Scenario
                    </button>
                </form>
                @endcan

                @if ($activeRun)
                    @can('soc:scenario.run')
                    <form method="POST" action="{{ route('scenario.runs.stop', $activeRun->id) }}">
                        @csrf
                        <button type="submit"
                                class="rounded-lg border border-red-400/40 bg-red-500/15 px-4 py-2 text-sm font-medium text-red-200 hover:bg-red-500/25 transition">
                            ■ Stop Active Run
                        </button>
                    </form>
                    @endcan
                @endif

                @if ($runs->isNotEmpty() && $runs->first()->validation_result)
                    @can('soc:scenario.export')
                    <a href="{{ route('scenario.reports.export', $runs->first()->id) }}"
                       class="rounded-lg border border-cyan-200/25 bg-cyan-100/5 px-4 py-2 text-sm text-cyan-200 hover:bg-cyan-100/15 transition">
                        ↓ Export Report
                    </a>
                    @endcan
                @endif
            </div>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-cyan-200/20 bg-cyan-100/10 p-3 text-sm text-cyan-50">{{ session('status') }}</div>
    @endif

    @if ($activeRun)
        <div class="mb-4 rounded-lg border border-cyan-400/30 bg-cyan-500/10 p-3 text-sm text-cyan-200">
            Active run <span class="mono-ui font-semibold">#{{ $activeRun->id }}</span> is
            <span class="font-semibold">{{ $activeRun->status }}</span> —
            <a href="{{ route('scenario.runs.timeline', $activeRun->id) }}" class="underline decoration-cyan-300/50">View Timeline</a>
        </div>
    @endif

    <div class="flex gap-6">
        @include('scenario._sidebar')

        <div class="min-w-0 flex-1 space-y-4">
            {{-- Description + expected telemetry --}}
            <div class="glass-card p-5">
                <h3 class="text-base font-semibold text-main-ui">Detection Specification</h3>
                <p class="mt-2 text-sm leading-relaxed text-cyan-100/75">{{ $scenario['description'] }}</p>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-4">
                        <p class="mb-2 text-xs uppercase tracking-[0.12em] text-cyan-100/55">Expected Telemetry</p>
                        <ul class="space-y-1.5 text-sm">
                            @foreach ($scenario['expected_telemetry'] as $k => $v)
                                <li class="flex items-start justify-between gap-2">
                                    <span class="text-cyan-100/55">{{ $k }}</span>
                                    <span class="mono-ui text-right text-cyan-200">{{ is_array($v) ? json_encode($v) : $v }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-4">
                        <p class="mb-2 text-xs uppercase tracking-[0.12em] text-cyan-100/55">Expected Detection</p>
                        <ul class="space-y-1.5 text-sm">
                            @foreach ($scenario['expected_detection'] as $k => $v)
                                <li class="flex items-start justify-between gap-2">
                                    <span class="text-cyan-100/55">{{ $k }}</span>
                                    <span class="mono-ui text-right text-cyan-200">{{ $v }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                <div class="mt-4">
                    <p class="mb-2 text-xs uppercase tracking-[0.12em] text-cyan-100/55">Pipeline Stages</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($scenario['pipeline_stages'] as $i => $stage)
                            <span class="flex items-center gap-1 rounded border border-cyan-200/20 bg-cyan-100/5 px-2 py-0.5 font-mono text-xs text-cyan-200">
                                <span class="text-cyan-100/40">{{ $i + 1 }}</span>
                                {{ $stage }}
                            </span>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Run history --}}
            <div class="glass-card p-5">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-base font-semibold text-main-ui">Run History</h3>
                    @if ($runs->isNotEmpty())
                        <a href="{{ route('scenario.runs.active') }}" class="text-xs text-cyan-300/70 underline decoration-cyan-300/30 hover:text-cyan-300">All runs →</a>
                    @endif
                </div>
                @if ($runs->isEmpty())
                    <p class="text-sm text-cyan-100/45">No runs recorded yet. Click <strong class="text-cyan-200">Run Scenario</strong> to begin.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-cyan-200/15 text-left text-xs uppercase tracking-[0.12em] text-cyan-100/55">
                                    <th class="pb-2 pr-4">ID</th>
                                    <th class="pb-2 pr-4">Mode</th>
                                    <th class="pb-2 pr-4">Status</th>
                                    <th class="pb-2 pr-4">Result</th>
                                    <th class="pb-2 pr-4">Alerts</th>
                                    <th class="pb-2 pr-4">Duration</th>
                                    <th class="pb-2 pr-4">Started</th>
                                    <th class="pb-2">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-cyan-200/10">
                                @foreach ($runs as $run)
                                    <tr class="text-cyan-100/80 hover:bg-cyan-100/5">
                                        <td class="mono-ui py-2.5 pr-4 text-cyan-200">#{{ $run->id }}</td>
                                        <td class="py-2.5 pr-4">
                                            <span class="rounded border border-cyan-200/20 px-1.5 py-0.5 text-xs text-cyan-300">{{ $run->run_mode }}</span>
                                        </td>
                                        <td class="py-2.5 pr-4">
                                            <span class="rounded border px-1.5 py-0.5 text-xs {{ $run->statusBadgeClass() }}">{{ $run->status }}</span>
                                        </td>
                                        <td class="py-2.5 pr-4">
                                            @if ($run->validation_result)
                                                <span class="rounded border px-1.5 py-0.5 text-xs {{ $run->validationBadgeClass() }}">{{ $run->validation_result }}</span>
                                            @else
                                                <span class="text-cyan-100/35">—</span>
                                            @endif
                                        </td>
                                        <td class="mono-ui py-2.5 pr-4">{{ $run->alerts_detected }}</td>
                                        <td class="mono-ui py-2.5 pr-4 text-cyan-100/60">
                                            {{ $run->durationSeconds() !== null ? $run->durationSeconds() . 's' : '—' }}
                                        </td>
                                        <td class="py-2.5 pr-4 text-xs text-cyan-100/55">{{ $run->started_at?->diffForHumans() ?? '—' }}</td>
                                        <td class="py-2.5">
                                            <div class="flex items-center gap-3">
                                                <a href="{{ route('scenario.runs.timeline', $run->id) }}"
                                                   class="text-xs text-cyan-300 underline decoration-cyan-300/40">Timeline</a>
                                                @can('soc:scenario.evidence.view')
                                                <a href="{{ route('scenario.runs.evidence', $run->id) }}"
                                                   class="text-xs text-cyan-300/70 underline decoration-cyan-300/30 hover:text-cyan-300">Evidence</a>
                                                @endcan
                                                @if ($run->validation_result)
                                                <a href="{{ route('scenario.runs.report', $run->id) }}"
                                                   class="text-xs text-cyan-300/70 underline decoration-cyan-300/30 hover:text-cyan-300">Report</a>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
