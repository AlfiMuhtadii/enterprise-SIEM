<aside class="glass-card w-56 shrink-0 p-4">
    <p class="mb-4 text-xs uppercase tracking-[0.14em] text-cyan-200/60">Endpoint Security</p>
    <nav class="space-y-1">
        <a href="{{ route('endpoint.index') }}"
           class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('endpoint.index') ? 'bg-cyan-100/10 text-cyan-50' : 'text-cyan-200/70 hover:bg-cyan-100/5 hover:text-cyan-50' }}">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
            </svg>
            Endpoint Inventory
        </a>
        <a href="{{ route('endpoint.index', ['status' => 'online']) }}"
           class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition text-cyan-200/70 hover:bg-cyan-100/5 hover:text-cyan-50">
            <span class="ml-0.5 h-2 w-2 rounded-full bg-green-400 shrink-0"></span>
            Online Agents
        </a>
        <a href="{{ route('endpoint.index', ['status' => 'offline']) }}"
           class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition text-cyan-200/70 hover:bg-cyan-100/5 hover:text-cyan-50">
            <span class="ml-0.5 h-2 w-2 rounded-full bg-red-400/60 shrink-0"></span>
            Offline Agents
        </a>
        <a href="{{ route('endpoint.health') }}"
           class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition text-cyan-200/70 hover:bg-cyan-100/5 hover:text-cyan-50"
           target="_blank">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
            </svg>
            Health API
        </a>
        <a href="{{ route('soc.agents') }}"
           class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition text-cyan-200/70 hover:bg-cyan-100/5 hover:text-cyan-50">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            Agent Management
        </a>
    </nav>

    <div class="mt-6 border-t border-cyan-100/15 pt-4 space-y-1 text-xs text-cyan-200/40">
        <div class="flex items-center gap-1.5"><span class="inline-block h-2 w-2 rounded-full bg-green-400"></span> Online</div>
        <div class="flex items-center gap-1.5"><span class="inline-block h-2 w-2 rounded-full bg-red-400/60"></span> Offline</div>
        <div class="mt-2 text-cyan-200/30">Shadow-only alerts. No active promotion.</div>
    </div>
</aside>
