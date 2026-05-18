<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="brand-chip">XDR Scenario Runner</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">Scenario Library</h2>
            </div>
            <span class="text-xs uppercase tracking-[0.14em] text-cyan-100/50">{{ $scenarios->count() }} scenarios</span>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-cyan-200/20 bg-cyan-100/10 p-3 text-sm text-cyan-50">{{ session('status') }}</div>
    @endif

    <div class="flex gap-6">
        @include('scenario._sidebar')

        <div class="min-w-0 flex-1">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($scenarios as $scenario)
                    @php
                        $run = $latestRuns->get($scenario['id']);
                        $severityColor = match($scenario['severity'] ?? 'medium') {
                            'critical' => 'text-red-300 border-red-400/40 bg-red-500/10',
                            'high'     => 'text-orange-300 border-orange-400/40 bg-orange-500/10',
                            'medium'   => 'text-yellow-300 border-yellow-400/40 bg-yellow-500/10',
                            default    => 'text-cyan-300 border-cyan-400/40 bg-cyan-500/10',
                        };
                        $resultColor = match($run?->validation_result) {
                            'PASS'    => 'border-green-400/30 bg-green-500/10 text-green-200',
                            'PARTIAL' => 'border-yellow-400/30 bg-yellow-500/10 text-yellow-200',
                            'FAIL'    => 'border-red-400/40 bg-red-500/10 text-red-200',
                            default   => 'border-cyan-200/20 bg-black/20 text-cyan-100/60',
                        };
                    @endphp
                    <div class="glass-card flex flex-col p-5">
                        {{-- Header --}}
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <span class="inline-block rounded border px-2 py-0.5 text-xs font-mono {{ $severityColor }}">
                                    {{ strtoupper($scenario['severity'] ?? 'medium') }}
                                </span>
                                <h3 class="mt-2 text-base font-semibold text-main-ui">{{ $scenario['title'] }}</h3>
                            </div>
                        </div>

                        <div class="mt-1.5 flex items-center gap-2">
                            <span class="mono-ui text-xs text-cyan-100/50">{{ $scenario['mitre_id'] }}</span>
                            <span class="text-xs text-cyan-100/40">·</span>
                            <span class="text-xs text-cyan-100/45">{{ $scenario['mitre_name'] }}</span>
                        </div>

                        <p class="mt-3 flex-1 text-sm leading-relaxed text-cyan-100/70">{{ $scenario['description'] }}</p>

                        {{-- Expected rule --}}
                        <div class="mt-4 rounded-lg border border-cyan-200/15 bg-black/20 px-3 py-2.5">
                            <p class="text-xs text-cyan-100/50">Expected Rule</p>
                            <p class="mono-ui mt-0.5 text-sm text-cyan-200">{{ $scenario['expected_rule'] }}</p>
                        </div>

                        {{-- Last run badge --}}
                        @if ($run)
                            <div class="mt-3 flex items-center justify-between gap-2 text-xs text-cyan-100/55">
                                <span>{{ $run->created_at->diffForHumans() }}</span>
                                <div class="flex items-center gap-1.5">
                                    @if ($run->validation_result)
                                        <span class="rounded border px-1.5 py-0.5 {{ $resultColor }}">{{ $run->validation_result }}</span>
                                    @else
                                        <span class="rounded border border-cyan-200/20 px-1.5 py-0.5 text-cyan-100/50">{{ $run->status }}</span>
                                    @endif
                                </div>
                            </div>
                        @else
                            <p class="mt-3 text-xs text-cyan-100/35">No runs yet</p>
                        @endif

                        {{-- Action buttons --}}
                        <div class="mt-4 space-y-2">
                            {{-- Primary: Run + Replay --}}
                            <div class="flex gap-2">
                                @can('soc:scenario.run')
                                <form method="POST" action="{{ route('scenario.runs.store') }}" class="flex-1">
                                    @csrf
                                    <input type="hidden" name="scenario_id" value="{{ $scenario['id'] }}">
                                    <input type="hidden" name="run_mode" value="live">
                                    <button type="submit"
                                            class="w-full rounded-lg border border-cyan-400/50 bg-cyan-500/20 px-3 py-2 text-sm font-semibold text-cyan-200 hover:bg-cyan-500/30 transition">
                                        ▶ Run Scenario
                                    </button>
                                </form>
                                @endcan

                                @can('soc:scenario.replay')
                                <form method="POST" action="{{ route('scenario.runs.store') }}" class="flex-1">
                                    @csrf
                                    <input type="hidden" name="scenario_id" value="{{ $scenario['id'] }}">
                                    <input type="hidden" name="run_mode" value="replay">
                                    <button type="submit"
                                            class="w-full rounded-lg border border-purple-400/40 bg-purple-500/15 px-3 py-2 text-sm font-medium text-purple-200 hover:bg-purple-500/25 transition">
                                        ↺ Replay
                                    </button>
                                </form>
                                @endcan
                            </div>

                            {{-- Secondary: View Details --}}
                            <a href="{{ route('scenario.library.show', $scenario['id']) }}"
                               class="block w-full rounded-lg border border-cyan-200/20 bg-transparent px-3 py-2 text-center text-sm text-cyan-100/70 hover:bg-cyan-100/5 hover:text-cyan-100 transition">
                                View Details
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
