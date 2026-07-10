<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Archive Search — Retention Archive</h2>
        <p class="text-xs text-amber-400/80 mt-1">Read-only. No mutation. Searches the gzip JSONL archive written before deletion by the retention job.</p>
    </x-slot>

    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            local safety-net search, not a real indexed warm tier — bounded scan, max {{ $maxResults }} results.
        </div>

        <section class="glass-card p-5">
            <form method="GET" action="{{ route('archive-search.index') }}" class="grid grid-cols-1 md:grid-cols-3 gap-2">
                <input type="text" name="table" value="{{ $table }}" placeholder="table (e.g. security_alerts)" required
                       class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <input type="text" name="filters" value="{{ $filters }}" placeholder="filters: field=value,field2=value2"
                       class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <input type="number" name="limit" value="{{ $limit }}" min="1" max="{{ $maxResults }}"
                       class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <input type="text" name="from" value="{{ $from }}" placeholder="from (e.g. 2026-06-01)"
                       class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <input type="text" name="to" value="{{ $to }}" placeholder="to (e.g. 2026-07-01)"
                       class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <button class="rounded border border-cyan-200/20 bg-cyan-100/10 px-4 py-2 text-sm text-cyan-50">Search</button>
            </form>

            <div class="mt-2 text-xs text-cyan-200/60">
                tenant scope: <span class="mono-ui">{{ $tenantId ?? 'unscoped (all tenants)' }}</span>
            </div>

            @if ($error)
                <div class="mt-3 rounded border border-red-400/30 bg-red-900/10 px-4 py-3 text-sm text-red-300">{{ $error }}</div>
            @endif

            @if ($result !== null)
                <div class="mt-4 text-xs text-cyan-200/60">
                    files scanned: {{ $result['files_scanned'] }} ·
                    rows scanned: {{ $result['rows_scanned'] }} ·
                    results: {{ $result['result_count'] }} ·
                    truncated: {{ $result['truncated'] ? 'yes' : 'no' }}
                </div>

                @if (empty($result['results']))
                    <p class="mt-3 text-xs text-muted-ui">No matches.</p>
                @else
                <div class="mt-3 space-y-2">
                    @foreach ($result['results'] as $row)
                    <pre class="rounded border border-cyan-200/10 bg-slate-950 px-3 py-2 text-xs text-cyan-50 overflow-x-auto">{{ json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    @endforeach
                </div>
                @endif
            @endif
        </section>
    </div>
</x-app-layout>
