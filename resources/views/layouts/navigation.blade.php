{{--
    Sidebar Navigation
    Mobile: drawer overlay (controlled by x-data mobileOpen in parent)
    Desktop: fixed left sidebar, always visible via lg:translate-x-0
--}}
<aside
    id="sidebar-nav"
    :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
    class="fixed inset-y-0 left-0 z-30 flex w-64 flex-col
           transition-transform duration-200 ease-in-out
           lg:translate-x-0
           border-r border-cyan-800/30"
    style="background: var(--bg-1);"
    aria-label="Sidebar"
>

    {{-- ── Logo ──────────────────────────────────────────────────────────── --}}
    <div class="flex h-16 shrink-0 items-center gap-3 border-b border-cyan-800/30 px-5">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 min-w-0">
            <x-application-logo class="h-8 w-8 shrink-0 fill-current text-cyan-400" />
            <div class="min-w-0">
                <div class="text-sm font-bold text-cyan-100 tracking-tight leading-tight truncate">XDR Platform</div>
                <div class="text-[10px] text-cyan-500/70 uppercase tracking-widest leading-tight">Security Ops</div>
            </div>
        </a>
    </div>

    {{-- ── Nav ───────────────────────────────────────────────────────────── --}}
    <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-4" x-data="{ groups: {} }" aria-label="Primary">

        {{-- ── Core ─────────────────────────────────────────────────────── --}}
        <div>
            <p class="sidebar-group-label">Core</p>
            <div class="mt-1 space-y-0.5">
                <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                    <x-slot name="icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    </x-slot>
                    Dashboard
                </x-nav-link>
                @if (in_array(Auth::user()?->role, ['admin', 'analyst', 'viewer'], true))
                    <x-nav-link :href="route('soc.dashboard')" :active="request()->routeIs('soc.dashboard')">
                        <x-slot name="icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                        </x-slot>
                        SOC Dashboard
                    </x-nav-link>
                @endif
                @if (Auth::user()?->role === 'admin')
                    <x-nav-link :href="route('security.alerts')" :active="request()->routeIs('security.alerts')">
                        <x-slot name="icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        </x-slot>
                        Security Alerts
                    </x-nav-link>
                @endif
            </div>
        </div>

        {{-- ── Investigate ───────────────────────────────────────────────── --}}
        @canany(['soc:investigation.view', 'soc:trace.view'])
        <div>
            <p class="sidebar-group-label">Investigate</p>
            <div class="mt-1 space-y-0.5">
                @can('soc:investigation.view')
                    <x-nav-link :href="route('investigation.index')" :active="request()->routeIs('investigation.*')">
                        <x-slot name="icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        </x-slot>
                        Investigations
                    </x-nav-link>
                    <x-nav-link :href="route('soc.workflow.dashboard')" :active="request()->routeIs('soc.workflow.*')">
                        <x-slot name="icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        </x-slot>
                        SOC Ops
                    </x-nav-link>
                    <x-nav-link :href="route('cross-domain.dashboard')" :active="request()->routeIs('cross-domain.*')">
                        <x-slot name="icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        </x-slot>
                        Cross-Domain
                    </x-nav-link>
                @endcan
                @can('soc:trace.view')
                    <x-nav-link :href="route('traces.index')" :active="request()->routeIs('traces.*')">
                        <x-slot name="icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        </x-slot>
                        Traces
                    </x-nav-link>
                @endcan
            </div>
        </div>
        @endcanany

        {{-- ── Detect ────────────────────────────────────────────────────── --}}
        @if (Auth::user()?->role === 'admin' || Gate::check('soc:rules.govern') || in_array(Auth::user()?->role, ['analyst', 'scenario_operator', 'detection_engineer']))
        <div>
            <p class="sidebar-group-label">Detect</p>
            <div class="mt-1 space-y-0.5">
                @can('soc:rules.govern')
                    <x-nav-link :href="route('detection.index')" :active="request()->routeIs('detection.*')">
                        <x-slot name="icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        </x-slot>
                        Detection
                    </x-nav-link>
                @endcan
                @if (Auth::user()?->role === 'admin')
                    <x-nav-link :href="route('soc.rules')" :active="request()->routeIs('soc.rules*')">
                        <x-slot name="icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                        </x-slot>
                        Rules
                    </x-nav-link>
                @endif
                @if (in_array(Auth::user()?->role, ['admin', 'analyst', 'scenario_operator', 'detection_engineer'], true))
                    <x-nav-link :href="route('scenario.library')" :active="request()->routeIs('scenario.*')">
                        <x-slot name="icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </x-slot>
                        Scenarios
                    </x-nav-link>
                @endif
            </div>
        </div>
        @endif

        {{-- ── Endpoint ───────────────────────────────────────────────────── --}}
        @if (in_array(Auth::user()?->role, ['admin', 'analyst', 'viewer', 'detection_engineer', 'scenario_operator'], true))
        <div>
            <p class="sidebar-group-label">Endpoint</p>
            <div class="mt-1 space-y-0.5">
                <x-nav-link :href="route('endpoint.index')" :active="request()->routeIs('endpoint.*') && !request()->routeIs('endpoint.response.*')">
                    <x-slot name="icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </x-slot>
                    Endpoint
                </x-nav-link>
                @can('soc:agents.manage')
                    <x-nav-link :href="route('endpoint.response.queue')" :active="request()->routeIs('endpoint.response.*')">
                        <x-slot name="icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                        </x-slot>
                        Response Queue
                    </x-nav-link>
                @endcan
            </div>
        </div>
        @endif

        {{-- ── Intelligence ──────────────────────────────────────────────── --}}
        @canany(['soc:trace.view', 'soc:entity.view', 'soc:investigation.view'])
        <div>
            <p class="sidebar-group-label">Intelligence</p>
            <div class="mt-1 space-y-0.5">
                @can('soc:trace.view')
                    <x-nav-link :href="route('network.dashboard')" :active="request()->routeIs('network.*')">
                        <x-slot name="icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/></svg>
                        </x-slot>
                        Network
                    </x-nav-link>
                @endcan
                @can('soc:entity.view')
                    <x-nav-link :href="route('entity.index')" :active="request()->routeIs('entity.*') && !request()->routeIs('entity.risk*')">
                        <x-slot name="icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                        </x-slot>
                        Entities
                    </x-nav-link>
                    <x-nav-link :href="route('entity.risk-dashboard')" :active="request()->routeIs('entity.risk*')">
                        <x-slot name="icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        </x-slot>
                        Risk Scoring
                    </x-nav-link>
                @endcan
                @can('soc:investigation.view')
                    <x-nav-link :href="route('integrations.dashboard')" :active="request()->routeIs('integrations.*')">
                        <x-slot name="icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"/></svg>
                        </x-slot>
                        Integrations
                    </x-nav-link>
                @endcan
            </div>
        </div>
        @endcanany

        {{-- ── Response ──────────────────────────────────────────────────── --}}
        @canany(['soc:response.view', 'soc:report.view'])
        <div>
            <p class="sidebar-group-label">Response</p>
            <div class="mt-1 space-y-0.5">
                @can('soc:response.view')
                    <x-nav-link :href="route('response.index')" :active="request()->routeIs('response.*')">
                        <x-slot name="icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </x-slot>
                        Response Plans
                    </x-nav-link>
                    <x-nav-link :href="route('active-response.dashboard')" :active="request()->routeIs('active-response.*')">
                        <x-slot name="icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </x-slot>
                        Active Response
                    </x-nav-link>
                    <x-nav-link :href="route('soar.governance-dashboard')" :active="request()->routeIs('soar.*')">
                        <x-slot name="icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        </x-slot>
                        SOAR
                    </x-nav-link>
                @endcan
                @can('soc:report.view')
                    <x-nav-link :href="route('export.index')" :active="request()->routeIs('export.*')">
                        <x-slot name="icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        </x-slot>
                        Export
                    </x-nav-link>
                @endcan
            </div>
        </div>
        @endcanany

        {{-- ── Platform (admin only) ─────────────────────────────────────── --}}
        @if (Auth::user()?->role === 'admin')
        <div>
            <p class="sidebar-group-label">Platform</p>
            <div class="mt-1 space-y-0.5">
                <x-nav-link :href="route('security.hardening')" :active="request()->routeIs('security.hardening')">
                    <x-slot name="icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </x-slot>
                    Security
                </x-nav-link>
                <x-nav-link :href="route('resilience.index')" :active="request()->routeIs('resilience.*')">
                    <x-slot name="icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </x-slot>
                    Resilience
                </x-nav-link>
                <x-nav-link :href="route('operations.health')" :active="request()->routeIs('operations.*')">
                    <x-slot name="icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
                    </x-slot>
                    Operations
                </x-nav-link>
                <x-nav-link :href="route('reliability.dashboard')" :active="request()->routeIs('reliability.*')">
                    <x-slot name="icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </x-slot>
                    Reliability
                </x-nav-link>
                <x-nav-link :href="route('governance.integrity-dashboard')" :active="request()->routeIs('governance.*')">
                    <x-slot name="icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </x-slot>
                    Governance
                </x-nav-link>
                <x-nav-link :href="route('capacity.dashboard')" :active="request()->routeIs('capacity.*')">
                    <x-slot name="icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </x-slot>
                    Capacity
                </x-nav-link>
                <x-nav-link :href="route('release.dashboard')" :active="request()->routeIs('release.*')">
                    <x-slot name="icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                    </x-slot>
                    Release
                </x-nav-link>
            </div>
        </div>
        @endif

    </nav>

    {{-- ── User / Profile ────────────────────────────────────────────────── --}}
    <div class="shrink-0 border-t border-cyan-800/30 px-4 py-3">
        <div class="flex items-center gap-3">
            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-cyan-800/50 text-xs font-bold text-cyan-300 uppercase">
                {{ substr(Auth::user()->name ?? 'U', 0, 1) }}
            </div>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-cyan-100">{{ Auth::user()->name }}</p>
                <p class="truncate text-xs text-cyan-400/70">{{ Auth::user()->email }}</p>
            </div>
        </div>
        <div class="mt-2 flex items-center gap-2">
            <a href="{{ route('profile.edit') }}" class="flex-1 rounded-md px-2 py-1 text-xs text-cyan-400/70 hover:text-cyan-200 hover:bg-cyan-900/30 transition-colors text-center">
                Profile
            </a>
            <form method="POST" action="{{ route('logout') }}" class="flex-1">
                @csrf
                <button type="submit" class="w-full rounded-md px-2 py-1 text-xs text-cyan-400/70 hover:text-red-300 hover:bg-red-900/20 transition-colors">
                    Log Out
                </button>
            </form>
        </div>
    </div>

</aside>
