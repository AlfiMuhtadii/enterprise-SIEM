<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="brand-chip">XDR Scenario Runner</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">Active Runs</h2>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs text-cyan-100/45">
                    {{ $runs->whereIn('status', ['pending','running'])->count() }} active
                    &middot; {{ $runs->count() }} total
                </span>
                <a href="{{ route('scenario.library') }}"
                   class="rounded-lg border border-cyan-200/25 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200 hover:bg-cyan-100/15 transition">
                    + New Run
                </a>
            </div>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-cyan-200/20 bg-cyan-100/10 p-3 text-sm text-cyan-50">{{ session('status') }}</div>
    @endif

    <div class="flex gap-6">
        @include('scenario._sidebar')

        <div class="min-w-0 flex-1">
            <div class="glass-card overflow-x-auto p-5">
                @if ($runs->isEmpty())
                    <p class="text-sm text-cyan-100/45">No runs recorded yet.</p>
                    <a href="{{ route('scenario.library') }}"
                       class="mt-3 inline-block text-sm text-cyan-300 underline decoration-cyan-300/40">
                        Go to Scenario Library →
                    </a>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-cyan-200/15 text-left text-xs uppercase tracking-[0.12em] text-cyan-100/50">
                                <th class="pb-2 pr-4">Run</th>
                                <th class="pb-2 pr-4">Scenario</th>
                                <th class="pb-2 pr-4">Mode</th>
                                <th class="pb-2 pr-4">Status</th>
                                <th class="pb-2 pr-4">Result</th>
                                <th class="pb-2 pr-4">Alerts</th>
                                <th class="pb-2 pr-4">Trace ID</th>
                                <th class="pb-2 pr-4">Started</th>
                                <th class="pb-2 pr-4">Completed</th>
                                <th class="pb-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyan-200/10">
                            @foreach ($runs as $run)
                                @php $def = $run->definition(); @endphp
                                <tr class="text-cyan-100/80 hover:bg-cyan-100/5">
                                    <td class="mono-ui py-2.5 pr-4 text-cyan-300">#{{ $run->id }}</td>
                                    <td class="py-2.5 pr-4">
                                        <a href="{{ route('scenario.library.show', $run->scenario_id) }}"
                                           class="text-cyan-100 hover:text-cyan-50">
                                            {{ $def['title'] ?? $run->scenario_id }}
                                        </a>
                                    </td>
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
                                            <span class="text-cyan-100/30">—</span>
                                        @endif
                                    </td>
                                    <td class="mono-ui py-2.5 pr-4">{{ $run->alerts_detected }}</td>
                                    <td class="mono-ui py-2.5 pr-4 max-w-[140px] truncate text-xs text-cyan-100/50" title="{{ $run->trace_id }}">
                                        {{ $run->trace_id }}
                                    </td>
                                    <td class="py-2.5 pr-4 text-xs text-cyan-100/55">{{ $run->started_at?->format('H:i:s') ?? '—' }}</td>
                                    <td class="py-2.5 pr-4 text-xs text-cyan-100/55">{{ $run->completed_at?->format('H:i:s') ?? '—' }}</td>
                                    <td class="py-2.5">
                                        <div class="flex items-center gap-2">
                                            <a href="{{ route('scenario.runs.timeline', $run->id) }}"
                                               class="text-xs text-cyan-300 underline decoration-cyan-300/40 hover:text-cyan-100">Timeline</a>

                                            @can('soc:scenario.evidence.view')
                                            <a href="{{ route('scenario.runs.evidence', $run->id) }}"
                                               class="text-xs text-cyan-300/65 underline decoration-cyan-300/30 hover:text-cyan-300">Evidence</a>
                                            @endcan

                                            @if ($run->validation_result)
                                            <a href="{{ route('scenario.runs.report', $run->id) }}"
                                               class="text-xs text-cyan-300/65 underline decoration-cyan-300/30 hover:text-cyan-300">Report</a>
                                            @endcan

                                            @can('soc:scenario.run')
                                            @if (in_array($run->status, ['pending','running'], true))
                                            <form method="POST" action="{{ route('scenario.runs.stop', $run->id) }}">
                                                @csrf
                                                <button type="submit"
                                                        class="text-xs text-red-300/65 underline decoration-red-300/30 hover:text-red-300">Stop</button>
                                            </form>
                                            @endif
                                            @endcan

                                            @can('soc:scenario.export')
                                            @if ($run->validation_result)
                                            <a href="{{ route('scenario.reports.export', $run->id) }}"
                                               class="text-xs text-cyan-300/55 underline decoration-cyan-300/25 hover:text-cyan-300">Export</a>
                                            @endif
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>

    @if ($runs->whereIn('status', ['pending', 'running'])->isNotEmpty())
        <script>setTimeout(() => window.location.reload(), 6000);</script>
    @endif
</x-app-layout>
