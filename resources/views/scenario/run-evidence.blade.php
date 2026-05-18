<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="brand-chip">Evidence Explorer</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">{{ $scenario['title'] }}</h2>
                <p class="mt-1 font-mono text-xs text-cyan-200/55">
                    Run #{{ $run->id }} &middot; {{ $run->trace_id }}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <span class="rounded border px-2 py-1 text-sm {{ $run->statusBadgeClass() }}">{{ $run->status }}</span>
                @if ($run->validation_result)
                    <span class="rounded border px-2 py-1 text-sm {{ $run->validationBadgeClass() }}">{{ $run->validation_result }}</span>
                @endif
                <a href="{{ route('scenario.runs.timeline', $run->id) }}"
                   class="rounded-lg border border-cyan-200/25 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200 hover:bg-cyan-100/15 transition">
                    ← Timeline
                </a>
                @if ($run->validation_result)
                <a href="{{ route('scenario.runs.report', $run->id) }}"
                   class="rounded-lg border border-cyan-200/25 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200 hover:bg-cyan-100/15 transition">
                    Report
                </a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="flex gap-6">
        @include('scenario._sidebar')

        <div class="min-w-0 flex-1 space-y-4">

            {{-- Stage group sections --}}
            @foreach ($stageGroups as $groupName => $groupStages)
                @php
                    $groupEvidence = collect();
                    foreach ($groupStages as $s) {
                        $groupEvidence = $groupEvidence->merge($evidenceByStage->get($s, collect()));
                    }
                @endphp
                @if ($groupEvidence->isNotEmpty())
                <div class="glass-card p-5">
                    <h3 class="mb-4 text-base font-semibold text-main-ui">{{ $groupName }}</h3>

                    <div class="space-y-3">
                        @foreach ($groupStages as $stage)
                            @php $stageItems = $evidenceByStage->get($stage, collect()); @endphp
                            @if ($stageItems->isNotEmpty())
                                @foreach ($stageItems as $ev)
                                    <div class="rounded-lg border border-cyan-200/15 bg-black/20">
                                        {{-- Stage header --}}
                                        <div class="flex items-center justify-between gap-3 border-b border-cyan-200/10 px-4 py-2.5">
                                            <div class="flex items-center gap-3">
                                                @php
                                                    $evDot = match($ev->status) {
                                                        'detected'   => 'bg-green-400',
                                                        'failed'     => 'bg-red-400',
                                                        'processing' => 'bg-cyan-400 animate-pulse',
                                                        default      => 'bg-cyan-800',
                                                    };
                                                @endphp
                                                <div class="h-2 w-2 rounded-full {{ $evDot }}"></div>
                                                <span class="mono-ui text-sm text-cyan-200">{{ $stage }}</span>
                                                @if ($ev->rule_id)
                                                    <span class="rounded border border-cyan-300/30 bg-cyan-500/10 px-1.5 py-0.5 text-xs text-cyan-200">{{ $ev->rule_id }}</span>
                                                @endif
                                                @if ($ev->severity)
                                                    @php
                                                        $sCol = match($ev->severity) {
                                                            'critical' => 'text-red-300',
                                                            'high'     => 'text-orange-300',
                                                            'medium'   => 'text-yellow-300',
                                                            default    => 'text-cyan-300',
                                                        };
                                                    @endphp
                                                    <span class="text-xs {{ $sCol }}">{{ strtoupper($ev->severity) }}</span>
                                                @endif
                                            </div>
                                            <div class="flex items-center gap-3 text-xs text-cyan-100/45">
                                                @if ($ev->latency_ms)
                                                    <span class="mono-ui">{{ $ev->latency_ms }}ms</span>
                                                @endif
                                                @if ($ev->processed_at)
                                                    <span>{{ $ev->processed_at->format('H:i:s.v') }}</span>
                                                @endif
                                                <span class="rounded border border-cyan-200/15 px-1.5 py-0.5 text-cyan-100/50">{{ $ev->status }}</span>
                                            </div>
                                        </div>

                                        {{-- IDs row --}}
                                        @if ($ev->event_id || $ev->trace_id)
                                            <div class="flex flex-wrap gap-x-6 gap-y-1 border-b border-cyan-200/8 px-4 py-2 text-xs">
                                                @if ($ev->event_id)
                                                    <span class="text-cyan-100/40">event_id: <span class="mono-ui text-cyan-200/70">{{ $ev->event_id }}</span></span>
                                                @endif
                                                @if ($ev->trace_id)
                                                    <span class="text-cyan-100/40">trace_id: <span class="mono-ui text-cyan-200/70">{{ $ev->trace_id }}</span></span>
                                                @endif
                                            </div>
                                        @endif

                                        {{-- Payload viewer --}}
                                        @if ($ev->payload)
                                            <div class="px-4 py-3">
                                                <p class="mb-2 text-xs text-cyan-100/40">Payload</p>
                                                <pre class="mono-ui max-h-64 overflow-auto rounded bg-black/40 p-3 text-xs leading-relaxed text-cyan-100/80 scrollbar-thin">{{ json_encode($ev->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            @endif
                        @endforeach
                    </div>
                </div>
                @endif
            @endforeach

            @if ($run->evidence->isEmpty())
                <div class="glass-card p-8 text-center">
                    <p class="text-sm text-cyan-100/45">No evidence records yet for this run.</p>
                    <a href="{{ route('scenario.runs.timeline', $run->id) }}"
                       class="mt-3 inline-block text-sm text-cyan-300 underline decoration-cyan-300/40">
                        ← Back to Timeline
                    </a>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
