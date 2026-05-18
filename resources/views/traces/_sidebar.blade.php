<aside class="glass-card w-56 shrink-0 p-4">
    <p class="mb-4 text-xs uppercase tracking-[0.14em] text-cyan-200/60">Trace Investigation</p>
    <nav class="space-y-1">
        <a href="{{ route('traces.index') }}"
           class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('traces.index') ? 'bg-cyan-100/10 text-cyan-50' : 'text-cyan-200/70 hover:bg-cyan-100/5 hover:text-cyan-50' }}">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            Trace Search
        </a>

        <a href="{{ route('api.traces.index') }}"
           class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition text-cyan-200/70 hover:bg-cyan-100/5 hover:text-cyan-50"
           target="_blank" rel="noopener">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
            </svg>
            Trace API
        </a>
    </nav>

    <div class="mt-6 border-t border-cyan-100/15 pt-4">
        <p class="mb-2 text-xs text-cyan-200/40">Search by</p>
        <div class="space-y-1 text-xs text-cyan-200/55">
            <div class="mono-ui">trace_id</div>
            <div class="mono-ui">alert_id</div>
            <div class="mono-ui">incident_id</div>
            <div class="mono-ui">actor_key</div>
            <div class="mono-ui">ip</div>
        </div>
    </div>
</aside>
