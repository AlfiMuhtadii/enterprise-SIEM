<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="brand-chip">Entity Graph</p>
                <h2 class="mt-2 text-xl font-semibold leading-tight text-main-ui">Entity Timeline</h2>
                <p class="mono-ui mt-1 truncate text-xs text-cyan-200/55">
                    <span class="rounded border border-cyan-200/20 bg-cyan-100/5 px-1.5 py-0.5 mr-1.5">{{ $entity->entity_type }}</span>
                    {{ $entity->entity_key }}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('entity.show', $entity->id) }}"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    ← Detail
                </a>
                <a href="{{ route('api.entities.timeline', $entity->id) }}" target="_blank" rel="noopener"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    JSON
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-5">

        {{-- Summary strip --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ([
                ['label' => 'Total Observations', 'value' => $timeline->count()],
                ['label' => 'Distinct Types',     'value' => $timeline->pluck('observation_type')->unique()->count()],
                ['label' => 'First Observed',     'value' => $timeline->isNotEmpty() ? \Carbon\Carbon::parse($timeline->first()->observed_at)->format('Y-m-d H:i') : '—'],
                ['label' => 'Last Observed',      'value' => $timeline->isNotEmpty() ? \Carbon\Carbon::parse($timeline->last()->observed_at)->format('Y-m-d H:i') : '—'],
            ] as $m)
                <div class="glass-card px-4 py-3">
                    <p class="text-xs text-cyan-200/45">{{ $m['label'] }}</p>
                    <p class="mt-1 text-base font-semibold text-cyan-50 mono-ui">{{ $m['value'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- Timeline list --}}
        @if ($timeline->isEmpty())
            <div class="glass-card p-8 text-center text-sm text-cyan-200/40">
                No observations recorded for this entity yet.
                <p class="mt-1 text-xs text-cyan-200/25">Observations are populated when entity projection runs against pipeline data.</p>
            </div>
        @else
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <span class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                        {{ $timeline->count() }} observation{{ $timeline->count() === 1 ? '' : 's' }} — append-only history
                    </span>
                </div>

                <div class="relative px-5 py-4">
                    {{-- Timeline vertical line --}}
                    <div class="absolute left-9 top-4 bottom-4 w-px bg-cyan-200/10"></div>

                    <div class="space-y-4">
                        @foreach ($timeline as $obs)
                            @php
                                $typeIcon = match(true) {
                                    str_contains($obs->observation_type, 'alert') => '🔔',
                                    str_contains($obs->observation_type, 'incident') => '📋',
                                    str_contains($obs->observation_type, 'actor') => '👤',
                                    str_contains($obs->observation_type, 'ip') => '🌐',
                                    default => '●',
                                };
                            @endphp
                            <div class="relative flex gap-4">
                                {{-- Node dot --}}
                                <div class="relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-cyan-400/30 bg-cyan-900/80 text-xs">
                                    <span class="text-cyan-400">●</span>
                                </div>

                                {{-- Content --}}
                                <div class="min-w-0 flex-1 rounded-lg border border-cyan-100/10 bg-black/20 px-4 py-3">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <span class="mono-ui text-xs font-medium text-cyan-200">
                                                {{ $obs->observation_type }}
                                            </span>
                                            @if ($obs->source_table)
                                                <span class="ml-2 text-xs text-cyan-200/40">from {{ $obs->source_table }}</span>
                                            @endif
                                        </div>
                                        <span class="mono-ui text-xs text-cyan-200/40">
                                            {{ \Carbon\Carbon::parse($obs->observed_at)->format('Y-m-d H:i:s') }}
                                        </span>
                                    </div>
                                    @if ($obs->source_event_id)
                                        <p class="mt-1 text-xs text-cyan-200/35">
                                            event: <span class="mono-ui">{{ $obs->source_event_id }}</span>
                                        </p>
                                    @endif
                                    @if ($obs->trace_id)
                                        <p class="mt-1 text-xs">
                                            trace:
                                            <a href="{{ route('traces.show', $obs->trace_id) }}"
                                               class="mono-ui text-cyan-400 hover:text-cyan-200 transition">
                                                {{ $obs->trace_id }}
                                            </a>
                                        </p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

    </div>
</x-app-layout>
