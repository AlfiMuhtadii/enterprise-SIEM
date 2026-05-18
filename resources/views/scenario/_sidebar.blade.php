<aside class="glass-card w-56 shrink-0 p-4">
    <p class="mb-4 text-xs uppercase tracking-[0.14em] text-cyan-200/60">Scenario Runner</p>
    <nav class="space-y-1">
        <a href="{{ route('scenario.library') }}"
           class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('scenario.library') || request()->routeIs('scenario.library.show') ? 'bg-cyan-100/10 text-cyan-50' : 'text-cyan-200/70 hover:bg-cyan-100/5 hover:text-cyan-50' }}">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
            </svg>
            Scenario Library
        </a>

        <a href="{{ route('scenario.runs.active') }}"
           class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('scenario.runs.*') ? 'bg-cyan-100/10 text-cyan-50' : 'text-cyan-200/70 hover:bg-cyan-100/5 hover:text-cyan-50' }}">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Active Runs
        </a>

        @can('soc:scenario.replay')
        <a href="{{ route('scenario.replay') }}"
           class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('scenario.replay') ? 'bg-cyan-100/10 text-cyan-50' : 'text-cyan-200/70 hover:bg-cyan-100/5 hover:text-cyan-50' }}">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Replay Validation
        </a>
        @endcan

        @can('soc:scenario.evidence.view')
        <a href="{{ route('scenario.evidence') }}"
           class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('scenario.evidence') ? 'bg-cyan-100/10 text-cyan-50' : 'text-cyan-200/70 hover:bg-cyan-100/5 hover:text-cyan-50' }}">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            Evidence Explorer
        </a>
        @endcan

        <a href="{{ route('scenario.reports') }}"
           class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('scenario.reports') ? 'bg-cyan-100/10 text-cyan-50' : 'text-cyan-200/70 hover:bg-cyan-100/5 hover:text-cyan-50' }}">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
            </svg>
            Validation Reports
        </a>
    </nav>
</aside>
