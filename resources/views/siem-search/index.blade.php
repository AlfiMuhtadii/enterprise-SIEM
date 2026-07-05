<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">SIEM Search — Raw Telemetry &amp; Alert Search</h2>
        <p class="text-xs text-amber-400/80 mt-1">Read-only. No mutation, no autonomous action. Results are tenant-scoped and redacted.</p>
    </x-slot>

    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            read-only — searches the OpenSearch alert index ({{ $windowDays }}-day window, max {{ $maxResults }} results), falling back to a bounded database search if OpenSearch is unavailable.
        </div>

        <section class="glass-card p-5">
            <form method="GET" action="{{ route('siem-search.index') }}" class="flex flex-wrap gap-2">
                <input type="text" name="q" value="{{ $query }}" minlength="{{ $minQueryLength }}" placeholder="search alert type, ip, evidence…" class="flex-1 min-w-[240px] rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <button class="rounded border border-cyan-200/20 bg-cyan-100/10 px-4 py-2 text-sm text-cyan-50">Search</button>
            </form>

            @if ($error)
                <div class="mt-3 rounded border border-red-400/30 bg-red-900/10 px-4 py-3 text-sm text-red-300">{{ $error }}</div>
            @endif

            @if ($result !== null)
                <div class="mt-4 text-xs text-cyan-200/60">
                    source: <span class="mono-ui">{{ $result['source'] }}</span> ·
                    window: {{ $result['window_days'] }}d ·
                    results: {{ $result['total'] }}
                </div>

                @if ($result['results']->isEmpty())
                    <p class="mt-3 text-xs text-muted-ui">No matches.</p>
                @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-xs text-cyan-50">
                        <thead><tr class="text-cyan-200/60 border-b border-cyan-200/20">
                            <th class="text-left py-1">Detected</th>
                            <th class="text-left py-1">Alert Type</th>
                            <th class="text-left py-1">Severity</th>
                            <th class="text-left py-1">IP</th>
                            <th class="text-left py-1">Detector</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($result['results'] as $row)
                        <tr class="border-b border-cyan-200/10">
                            <td class="py-1 mono-ui">{{ $row->detected_at ?? '-' }}</td>
                            <td class="py-1">{{ $row->alert_type ?? '-' }}</td>
                            <td class="py-1">{{ $row->severity ?? '-' }}</td>
                            <td class="py-1 mono-ui">{{ $row->ip ?? '-' }}</td>
                            <td class="py-1">{{ $row->detector_name ?? '-' }}</td>
                        </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            @endif
        </section>
    </div>
</x-app-layout>
