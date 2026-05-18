<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="brand-chip">XDR Scenario Runner</p>
            <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">Replay Validation</h2>
        </div>
    </x-slot>

    <div class="flex gap-6">
        @include('scenario._sidebar')

        <div class="min-w-0 flex-1 space-y-4">
            {{-- Replay launcher --}}
            @can('soc:scenario.replay')
            <div class="glass-card p-5">
                <h3 class="mb-4 text-base font-semibold text-main-ui">Launch Replay Run</h3>
                <form method="POST" action="{{ route('scenario.replay.store') }}" class="flex flex-wrap gap-3">
                    @csrf
                    <select name="scenario_id" required
                            class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                        <option value="">Select scenario…</option>
                        @foreach (config('scenarios.scenarios', []) as $s)
                            <option value="{{ $s['id'] }}">{{ $s['title'] }}</option>
                        @endforeach
                    </select>
                    <select name="source_run_id"
                            class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                        <option value="">New replay (no source run)</option>
                        @foreach ($runs as $run)
                            <option value="{{ $run->id }}">#{{ $run->id }} — {{ $run->scenario_id }}</option>
                        @endforeach
                    </select>
                    <button type="submit"
                            class="rounded-lg border border-purple-400/40 bg-purple-500/15 px-4 py-2 text-sm font-medium text-purple-200 hover:bg-purple-500/25 transition">
                        Launch Replay
                    </button>
                </form>
                <p class="mt-3 text-xs text-cyan-100/50">
                    Replay runs re-inject the same synthetic telemetry payload to validate detection parity across pipeline versions.
                    They are always marked <span class="mono-ui">run_mode=replay</span>.
                </p>
            </div>
            @endcan

            {{-- Replay history --}}
            <div class="glass-card overflow-x-auto p-5">
                <h3 class="mb-3 text-base font-semibold text-main-ui">Replay History</h3>
                @if ($runs->isEmpty())
                    <p class="text-sm text-cyan-100/50">No replay runs recorded yet.</p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-cyan-200/15 text-left text-xs uppercase tracking-[0.12em] text-cyan-100/60">
                                <th class="pb-2 pr-4">Run</th>
                                <th class="pb-2 pr-4">Scenario</th>
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
                                @php $def = $run->definition(); @endphp
                                <tr class="text-cyan-100/80 hover:bg-cyan-100/5">
                                    <td class="mono-ui py-2.5 pr-4 text-cyan-300">#{{ $run->id }}</td>
                                    <td class="py-2.5 pr-4">{{ $def['title'] ?? $run->scenario_id }}</td>
                                    <td class="py-2.5 pr-4">
                                        <span class="rounded border px-1.5 py-0.5 text-xs {{ $run->statusBadgeClass() }}">{{ $run->status }}</span>
                                    </td>
                                    <td class="py-2.5 pr-4">
                                        @if ($run->validation_result)
                                            <span class="rounded border px-1.5 py-0.5 text-xs {{ $run->validationBadgeClass() }}">{{ $run->validation_result }}</span>
                                        @else
                                            <span class="text-cyan-100/40">—</span>
                                        @endif
                                    </td>
                                    <td class="mono-ui py-2.5 pr-4">{{ $run->alerts_detected }}</td>
                                    <td class="mono-ui py-2.5 pr-4">{{ $run->durationSeconds() !== null ? $run->durationSeconds() . 's' : '—' }}</td>
                                    <td class="py-2.5 pr-4 text-xs text-cyan-100/60">{{ $run->started_at?->diffForHumans() ?? '—' }}</td>
                                    <td class="py-2.5">
                                        <a href="{{ route('scenario.runs.timeline', $run->id) }}"
                                           class="text-xs text-cyan-300 underline decoration-cyan-300/40">Timeline</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
