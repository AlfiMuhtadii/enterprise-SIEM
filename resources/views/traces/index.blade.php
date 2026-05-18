<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="brand-chip">XDR Platform</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">Trace Investigation</h2>
            </div>
            <span class="text-xs uppercase tracking-[0.14em] text-cyan-100/50">Distributed Trace Correlation</span>
        </div>
    </x-slot>

    <div class="flex gap-6">
        @include('traces._sidebar')

        <div class="min-w-0 flex-1 space-y-4">

            {{-- Search form --}}
            <div class="glass-card p-5">
                <form method="GET" action="{{ route('traces.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <label class="mb-1.5 block text-xs text-cyan-200/60">Search value</label>
                        <input type="text" name="q" value="{{ $q }}"
                               placeholder="e.g. 3a7f1c2e-..."
                               class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 placeholder-cyan-200/30 focus:border-cyan-400/40 focus:outline-none mono-ui" />
                    </div>
                    <div class="w-40">
                        <label class="mb-1.5 block text-xs text-cyan-200/60">Search by</label>
                        <select name="by"
                                class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:border-cyan-400/40 focus:outline-none">
                            @foreach (['trace_id' => 'Trace ID', 'alert_id' => 'Alert ID', 'incident_id' => 'Incident ID', 'actor_key' => 'Actor Key', 'ip' => 'IP Address'] as $val => $label)
                                <option value="{{ $val }}" @selected($by === $val)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit"
                            class="rounded-lg border border-cyan-400/40 bg-cyan-500/15 px-5 py-2 text-sm font-medium text-cyan-200 hover:bg-cyan-500/25 transition">
                        Search
                    </button>
                </form>
            </div>

            {{-- Results --}}
            @if ($q !== '' && $traces->isEmpty())
                <div class="glass-card p-8 text-center text-sm text-cyan-200/40">
                    No traces found for <span class="mono-ui text-cyan-200/60">{{ $q }}</span>
                </div>
            @elseif ($traces->isNotEmpty())
                <div class="glass-card overflow-hidden">
                    <div class="border-b border-cyan-100/10 px-5 py-3">
                        <span class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                            {{ $traces->count() }} trace{{ $traces->count() === 1 ? '' : 's' }} found
                        </span>
                    </div>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.1em] text-cyan-200/40">
                                <th class="px-5 py-3">Trace ID</th>
                                <th class="px-4 py-3">First Seen</th>
                                <th class="px-4 py-3">Last Seen</th>
                                <th class="px-4 py-3 text-center">Events</th>
                                <th class="px-4 py-3 text-center">Alerts</th>
                                <th class="px-4 py-3 text-center">Incidents</th>
                                <th class="px-4 py-3">Severity</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyan-100/5">
                            @foreach ($traces as $trace)
                                @php
                                    $sevColor = match($trace->top_severity) {
                                        'critical' => 'text-red-300 border-red-400/40 bg-red-500/10',
                                        'high'     => 'text-orange-300 border-orange-400/40 bg-orange-500/10',
                                        'medium'   => 'text-yellow-300 border-yellow-400/40 bg-yellow-500/10',
                                        'low'      => 'text-cyan-300 border-cyan-400/40 bg-cyan-500/10',
                                        default    => 'text-cyan-100/40 border-cyan-200/15 bg-black/20',
                                    };
                                @endphp
                                <tr class="hover:bg-cyan-100/3 transition">
                                    <td class="px-5 py-3">
                                        <span class="mono-ui text-xs text-cyan-200/80">{{ $trace->trace_id }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-cyan-200/55">
                                        {{ $trace->first_seen ? \Carbon\Carbon::parse($trace->first_seen)->format('Y-m-d H:i:s') : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-xs text-cyan-200/55">
                                        {{ $trace->last_seen ? \Carbon\Carbon::parse($trace->last_seen)->format('Y-m-d H:i:s') : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-center text-xs text-cyan-200/70">{{ $trace->events_count }}</td>
                                    <td class="px-4 py-3 text-center text-xs text-cyan-200/70">{{ $trace->alerts_count }}</td>
                                    <td class="px-4 py-3 text-center text-xs text-cyan-200/70">{{ $trace->incidents_count }}</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded border px-1.5 py-0.5 text-xs {{ $sevColor }}">{{ strtoupper($trace->top_severity) }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('traces.show', $trace->trace_id) }}"
                                           class="text-xs text-cyan-400 hover:text-cyan-200 transition">
                                            Investigate →
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="glass-card p-8 text-center">
                    <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full border border-cyan-200/20 bg-cyan-100/5">
                        <svg class="h-6 w-6 text-cyan-200/40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>
                    <p class="text-sm text-cyan-200/50">Search by trace_id, alert_id, incident_id, actor key, or IP address.</p>
                    <p class="mt-1 text-xs text-cyan-200/30">Each result links to the full distributed trace timeline.</p>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
