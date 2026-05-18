<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="brand-chip">XDR Scenario Runner</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">Evidence Explorer</h2>
            </div>
            <span class="text-xs text-cyan-100/50">{{ $evidence->count() }} items</span>
        </div>
    </x-slot>

    <div class="flex gap-6">
        @include('scenario._sidebar')

        <div class="min-w-0 flex-1 space-y-4">
            {{-- Filters --}}
            <form method="GET" action="{{ route('scenario.evidence') }}"
                  class="glass-card flex flex-wrap gap-3 p-4">
                <select name="run_id"
                        class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                    <option value="">All runs</option>
                    @foreach ($runs as $run)
                        <option value="{{ $run->id }}" @selected((string)($filters['runId'] ?? '') === (string)$run->id)>
                            #{{ $run->id }} — {{ $run->scenario_id }} ({{ $run->status }})
                        </option>
                    @endforeach
                </select>

                <select name="stage"
                        class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                    <option value="">All stages</option>
                    @foreach ($stages as $s)
                        <option value="{{ $s }}" @selected(($filters['stage'] ?? '') === $s)>{{ $s }}</option>
                    @endforeach
                </select>

                <select name="status"
                        class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                    <option value="">All status</option>
                    @foreach (['pending', 'processing', 'detected', 'failed'] as $st)
                        <option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>{{ $st }}</option>
                    @endforeach
                </select>

                <button class="rounded-lg border border-cyan-200/30 bg-cyan-100/10 px-4 py-2 text-sm font-medium text-cyan-50 hover:bg-cyan-100/20">
                    Filter
                </button>
                <a href="{{ route('scenario.evidence') }}"
                   class="rounded-lg border border-cyan-200/20 px-4 py-2 text-sm text-cyan-100/60 hover:text-cyan-100">
                    Clear
                </a>
            </form>

            {{-- Stage tab summary --}}
            @if ($evidence->isNotEmpty())
                @php
                    $byStage = $evidence->groupBy('stage');
                @endphp
                <div class="flex flex-wrap gap-2">
                    @foreach ($byStage as $stage => $items)
                        @php
                            $hasDetected = $items->where('status', 'detected')->isNotEmpty();
                            $hasFailed   = $items->where('status', 'failed')->isNotEmpty();
                            $chipColor = $hasFailed ? 'border-red-400/40 bg-red-500/10 text-red-200' : ($hasDetected ? 'border-green-400/30 bg-green-500/10 text-green-200' : 'border-cyan-200/20 bg-cyan-100/5 text-cyan-300');
                        @endphp
                        <span class="rounded-full border px-3 py-1 text-xs font-mono {{ $chipColor }}">
                            {{ $stage }} <span class="opacity-70">({{ $items->count() }})</span>
                        </span>
                    @endforeach
                </div>
            @endif

            {{-- Evidence table --}}
            <div class="glass-card overflow-x-auto p-5">
                @if ($evidence->isEmpty())
                    <p class="text-sm text-cyan-100/50">No evidence records match the current filter.</p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-cyan-200/15 text-left text-xs uppercase tracking-[0.12em] text-cyan-100/60">
                                <th class="pb-2 pr-4">Run</th>
                                <th class="pb-2 pr-4">Stage</th>
                                <th class="pb-2 pr-4">Status</th>
                                <th class="pb-2 pr-4">Rule</th>
                                <th class="pb-2 pr-4">Severity</th>
                                <th class="pb-2 pr-4">Event ID</th>
                                <th class="pb-2 pr-4">Trace ID</th>
                                <th class="pb-2">Processed</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyan-200/10">
                            @foreach ($evidence as $ev)
                                <tr class="text-cyan-100/80 hover:bg-cyan-100/5">
                                    <td class="py-2 pr-4">
                                        <a href="{{ route('scenario.runs.timeline', $ev->scenario_run_id) }}"
                                           class="mono-ui text-cyan-300 underline decoration-cyan-300/40">#{{ $ev->scenario_run_id }}</a>
                                    </td>
                                    <td class="mono-ui py-2 pr-4 text-xs text-cyan-200">{{ $ev->stage }}</td>
                                    <td class="py-2 pr-4">
                                        @php
                                            $sc = match($ev->status) {
                                                'detected'   => 'border-green-400/30 bg-green-500/10 text-green-200',
                                                'failed'     => 'border-red-400/40 bg-red-500/10 text-red-200',
                                                'processing' => 'border-cyan-400/30 bg-cyan-500/10 text-cyan-200',
                                                default      => 'border-cyan-200/20 bg-black/20 text-cyan-100/60',
                                            };
                                        @endphp
                                        <span class="rounded border px-1.5 py-0.5 text-xs {{ $sc }}">{{ $ev->status }}</span>
                                    </td>
                                    <td class="mono-ui py-2 pr-4 text-xs">{{ $ev->rule_id ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-xs">{{ $ev->severity ?? '—' }}</td>
                                    <td class="mono-ui py-2 pr-4 max-w-[160px] truncate text-xs text-cyan-100/70" title="{{ $ev->event_id }}">{{ $ev->event_id ?? '—' }}</td>
                                    <td class="mono-ui py-2 pr-4 max-w-[140px] truncate text-xs text-cyan-100/50" title="{{ $ev->trace_id }}">{{ $ev->trace_id ?? '—' }}</td>
                                    <td class="py-2 text-xs text-cyan-100/50">{{ $ev->processed_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
