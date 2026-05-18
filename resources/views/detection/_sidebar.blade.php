<aside class="glass-card w-56 shrink-0 p-4">
    <p class="mb-4 text-xs uppercase tracking-[0.14em] text-cyan-200/60">Detection Rules</p>
    <nav class="space-y-1">
        <a href="{{ route('detection.index') }}"
           class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('detection.index') ? 'bg-cyan-100/10 text-cyan-50' : 'text-cyan-200/70 hover:bg-cyan-100/5 hover:text-cyan-50' }}">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
            </svg>
            Rule Registry
        </a>
        <a href="{{ route('detection.index', ['stage' => 'staged_active']) }}"
           class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition text-cyan-200/70 hover:bg-cyan-100/5 hover:text-cyan-50">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Active Rules
        </a>
        <a href="{{ route('detection.index', ['stage' => 'shadow']) }}"
           class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition text-cyan-200/70 hover:bg-cyan-100/5 hover:text-cyan-50">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
            </svg>
            Shadow Rules
        </a>
        <a href="{{ route('detection.index') }}#mitre"
           class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition text-cyan-200/70 hover:bg-cyan-100/5 hover:text-cyan-50">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
            </svg>
            MITRE Coverage
        </a>
    </nav>

    <div class="mt-6 border-t border-cyan-100/15 pt-4 space-y-1 text-xs text-cyan-200/40">
        <div class="flex items-center gap-1.5"><span class="inline-block h-2 w-2 rounded-full bg-green-400"></span> staged_active</div>
        <div class="flex items-center gap-1.5"><span class="inline-block h-2 w-2 rounded-full bg-yellow-400"></span> shadow</div>
        <div class="flex items-center gap-1.5"><span class="inline-block h-2 w-2 rounded-full bg-cyan-400"></span> draft</div>
        <div class="flex items-center gap-1.5"><span class="inline-block h-2 w-2 rounded-full bg-red-400/60"></span> deprecated</div>
    </div>
</aside>
