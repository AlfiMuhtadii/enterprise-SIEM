<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="brand-chip">XDR Scenario Runner</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">Validation Reports</h2>
            </div>
            <span class="text-xs text-cyan-100/50">{{ $summary['total'] }} completed runs</span>
        </div>
    </x-slot>

    <div class="flex gap-6">
        @include('scenario._sidebar')

        <div class="min-w-0 flex-1 space-y-4">
            {{-- Summary row --}}
            <div class="grid gap-3 sm:grid-cols-4">
                <div class="metric-card">
                    <p class="text-xs uppercase tracking-[0.14em] text-cyan-200/75">Total Runs</p>
                    <p class="mt-2 text-3xl font-semibold text-main-ui">{{ $summary['total'] }}</p>
                </div>
                <div class="metric-card">
                    <p class="text-xs uppercase tracking-[0.14em] text-green-300/75">PASS</p>
                    <p class="mt-2 text-3xl font-semibold text-green-300">{{ $summary['passed'] }}</p>
                </div>
                <div class="metric-card">
                    <p class="text-xs uppercase tracking-[0.14em] text-yellow-300/75">PARTIAL</p>
                    <p class="mt-2 text-3xl font-semibold text-yellow-300">{{ $summary['partial'] }}</p>
                </div>
                <div class="metric-card">
                    <p class="text-xs uppercase tracking-[0.14em] text-red-300/75">FAIL</p>
                    <p class="mt-2 text-3xl font-semibold text-red-300">{{ $summary['failed'] }}</p>
                </div>
            </div>

            {{-- Report table --}}
            <div class="glass-card overflow-x-auto p-5">
                @if ($runs->isEmpty())
                    <p class="text-sm text-cyan-100/50">No completed runs with validation results yet.</p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-cyan-200/15 text-left text-xs uppercase tracking-[0.12em] text-cyan-100/60">
                                <th class="pb-2 pr-4">Run</th>
                                <th class="pb-2 pr-4">Scenario</th>
                                <th class="pb-2 pr-4">Mode</th>
                                <th class="pb-2 pr-4">Result</th>
                                <th class="pb-2 pr-4">Detection</th>
                                <th class="pb-2 pr-4">Alerts</th>
                                <th class="pb-2 pr-4">Duration</th>
                                <th class="pb-2 pr-4">Started</th>
                                <th class="pb-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyan-200/10">
                            @foreach ($runs as $run)
                                @php
                                    $def = $run->definition();
                                @endphp
                                <tr class="text-cyan-100/80 hover:bg-cyan-100/5">
                                    <td class="py-2.5 pr-4">
                                        <a href="{{ route('scenario.runs.timeline', $run->id) }}"
                                           class="mono-ui text-cyan-300 underline decoration-cyan-300/40">#{{ $run->id }}</a>
                                    </td>
                                    <td class="py-2.5 pr-4">
                                        <a href="{{ route('scenario.library.show', $run->scenario_id) }}"
                                           class="text-cyan-100 hover:text-cyan-50">
                                            {{ $def['title'] ?? $run->scenario_id }}
                                        </a>
                                        @if ($def)
                                            <p class="mono-ui text-xs text-cyan-100/40">{{ $def['mitre_id'] }}</p>
                                        @endif
                                    </td>
                                    <td class="py-2.5 pr-4">
                                        <span class="rounded border border-cyan-200/20 px-1.5 py-0.5 text-xs text-cyan-300">{{ $run->run_mode }}</span>
                                    </td>
                                    <td class="py-2.5 pr-4">
                                        <span class="rounded border px-1.5 py-0.5 text-xs {{ $run->validationBadgeClass() }}">
                                            {{ $run->validation_result }}
                                        </span>
                                    </td>
                                    <td class="py-2.5 pr-4">
                                        @if ($run->detection_passed === true)
                                            <span class="text-green-300">✓</span>
                                        @elseif ($run->detection_passed === false)
                                            <span class="text-red-300">✗</span>
                                        @else
                                            <span class="text-cyan-100/40">—</span>
                                        @endif
                                    </td>
                                    <td class="mono-ui py-2.5 pr-4">{{ $run->alerts_detected }}</td>
                                    <td class="mono-ui py-2.5 pr-4">{{ $run->durationSeconds() !== null ? $run->durationSeconds() . 's' : '—' }}</td>
                                    <td class="py-2.5 pr-4 text-xs text-cyan-100/60">{{ $run->started_at?->diffForHumans() ?? '—' }}</td>
                                    <td class="py-2.5">
                                        <div class="flex items-center gap-2">
                                            <a href="{{ route('scenario.runs.timeline', $run->id) }}"
                                               class="text-xs text-cyan-300 underline decoration-cyan-300/40">Timeline</a>
                                            @can('soc:scenario.export')
                                            <a href="{{ route('scenario.reports.export', $run->id) }}"
                                               class="text-xs text-cyan-300/70 underline decoration-cyan-300/30 hover:text-cyan-300">Export</a>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                                @if ($run->failure_reason)
                                    <tr class="bg-red-500/5">
                                        <td colspan="9" class="mono-ui pb-2 pl-4 text-xs text-red-200/80">
                                            {{ $run->failure_reason }}
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
