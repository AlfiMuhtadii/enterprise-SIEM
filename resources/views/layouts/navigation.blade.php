<nav x-data="{ open: false }" class="px-4 pt-4 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-7xl">
        <div class="glass-card flex h-16 justify-between px-4 sm:px-6">
            <div class="flex">
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}">
                        <x-application-logo class="block h-9 w-auto fill-current text-cyan-200" />
                    </a>
                </div>

                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        {{ __('Dashboard') }}
                    </x-nav-link>
                    @if (Auth::user()?->role === 'admin')
                        <x-nav-link :href="route('security.alerts')" :active="request()->routeIs('security.alerts')">
                            {{ __('Security Alerts') }}
                        </x-nav-link>
                    @endif
                    @if (in_array(Auth::user()?->role, ['admin', 'analyst', 'viewer'], true))
                        <x-nav-link :href="route('soc.dashboard')" :active="request()->routeIs('soc.*')">
                            {{ __('SOC') }}
                        </x-nav-link>
                    @endif
                    @if (Auth::user()?->role === 'admin')
                        <x-nav-link :href="route('soc.rules')" :active="request()->routeIs('soc.rules*')">
                            {{ __('Rules') }}
                        </x-nav-link>
                    @endif
                    @if (in_array(Auth::user()?->role, ['admin', 'analyst', 'scenario_operator', 'detection_engineer'], true))
                        <x-nav-link :href="route('scenario.library')" :active="request()->routeIs('scenario.*')">
                            {{ __('Scenarios') }}
                        </x-nav-link>
                    @endif
                    @can('soc:trace.view')
                        <x-nav-link :href="route('traces.index')" :active="request()->routeIs('traces.*')">
                            {{ __('Traces') }}
                        </x-nav-link>
                    @endcan
                    @can('soc:trace.view')
                        <x-nav-link :href="route('network.dashboard')" :active="request()->routeIs('network.*')">
                            {{ __('Network') }}
                        </x-nav-link>
                    @endcan
                    @can('soc:rules.govern')
                        <x-nav-link :href="route('detection.index')" :active="request()->routeIs('detection.*')">
                            {{ __('Detection') }}
                        </x-nav-link>
                    @endcan
                    @if (in_array(Auth::user()?->role, ['admin', 'analyst', 'viewer', 'detection_engineer', 'scenario_operator'], true))
                        <x-nav-link :href="route('endpoint.index')" :active="request()->routeIs('endpoint.*')">
                            {{ __('Endpoint') }}
                        </x-nav-link>
                    @endif
                    @can('soc:entity.view')
                        <x-nav-link :href="route('entity.index')" :active="request()->routeIs('entity.*') && !request()->routeIs('entity.risk*')">
                            {{ __('Entities') }}
                        </x-nav-link>
                        <x-nav-link :href="route('entity.risk-dashboard')" :active="request()->routeIs('entity.risk*')">
                            {{ __('Risk') }}
                        </x-nav-link>
                    @endcan
                    @can('soc:investigation.view')
                        <x-nav-link :href="route('investigation.index')" :active="request()->routeIs('investigation.*')">
                            {{ __('Investigations') }}
                        </x-nav-link>
                    @endcan
                    @can('soc:investigation.view')
                        <x-nav-link :href="route('soc.workflow.dashboard')" :active="request()->routeIs('soc.workflow.*')">
                            {{ __('SOC Ops') }}
                        </x-nav-link>
                    @endcan
                    @can('soc:investigation.view')
                        <x-nav-link :href="route('cross-domain.dashboard')" :active="request()->routeIs('cross-domain.*')">
                            {{ __('Cross-Domain') }}
                        </x-nav-link>
                    @endcan
                    @can('soc:response.view')
                        <x-nav-link :href="route('response.index')" :active="request()->routeIs('response.*')">
                            {{ __('Response') }}
                        </x-nav-link>
                    @endcan
                    @can('soc:response.view')
                        <x-nav-link :href="route('active-response.dashboard')" :active="request()->routeIs('active-response.*')">
                            {{ __('Active Response') }}
                        </x-nav-link>
                    @endcan
                    @can('soc:report.view')
                        <x-nav-link :href="route('export.index')" :active="request()->routeIs('export.*')">
                            {{ __('Export') }}
                        </x-nav-link>
                    @endcan
                    @can('soc:agents.manage')
                        <x-nav-link :href="route('endpoint.response.queue')" :active="request()->routeIs('endpoint.response.*')">
                            {{ __('Response') }}
                        </x-nav-link>
                    @endcan
                    @if (Auth::user()?->role === 'admin')
                        <x-nav-link :href="route('security.hardening')" :active="request()->routeIs('security.hardening')">
                            {{ __('Security') }}
                        </x-nav-link>
                        <x-nav-link :href="route('resilience.index')" :active="request()->routeIs('resilience.*')">
                            {{ __('Resilience') }}
                        </x-nav-link>
                        <x-nav-link :href="route('operations.health')" :active="request()->routeIs('operations.*')">
                            {{ __('Operations') }}
                        </x-nav-link>
                    @endif
                </div>
            </div>

            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center rounded-full border border-cyan-200/30 bg-cyan-50/5 px-3 py-2 text-sm leading-4 font-medium text-cyan-100 hover:bg-cyan-100/10 focus:outline-none transition ease-in-out duration-150">
                            <div class="truncate max-w-28">{{ Auth::user()->name }}</div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault();
                                                this.closest('form').submit();">
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center rounded-md p-2 text-cyan-100 hover:bg-cyan-100/10 focus:outline-none transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="glass-card mt-2 space-y-1 px-2 py-3">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                {{ __('Dashboard') }}
            </x-responsive-nav-link>
            @if (Auth::user()?->role === 'admin')
                <x-responsive-nav-link :href="route('security.alerts')" :active="request()->routeIs('security.alerts')">
                    {{ __('Security Alerts') }}
                </x-responsive-nav-link>
            @endif
            @if (in_array(Auth::user()?->role, ['admin', 'analyst', 'viewer'], true))
                <x-responsive-nav-link :href="route('soc.dashboard')" :active="request()->routeIs('soc.*')">
                    {{ __('SOC') }}
                </x-responsive-nav-link>
            @endif
            @if (Auth::user()?->role === 'admin')
                <x-responsive-nav-link :href="route('soc.rules')" :active="request()->routeIs('soc.rules*')">
                    {{ __('Rules') }}
                </x-responsive-nav-link>
            @endif
            @if (in_array(Auth::user()?->role, ['admin', 'analyst', 'scenario_operator', 'detection_engineer'], true))
                <x-responsive-nav-link :href="route('scenario.library')" :active="request()->routeIs('scenario.*')">
                    {{ __('Scenarios') }}
                </x-responsive-nav-link>
            @endif
            @can('soc:trace.view')
                <x-responsive-nav-link :href="route('traces.index')" :active="request()->routeIs('traces.*')">
                    {{ __('Traces') }}
                </x-responsive-nav-link>
            @endcan
            @can('soc:rules.govern')
                <x-responsive-nav-link :href="route('detection.index')" :active="request()->routeIs('detection.*')">
                    {{ __('Detection') }}
                </x-responsive-nav-link>
            @endcan
            @can('soc:entity.view')
                <x-responsive-nav-link :href="route('entity.index')" :active="request()->routeIs('entity.*') && !request()->routeIs('entity.risk*')">
                    {{ __('Entities') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('entity.risk-dashboard')" :active="request()->routeIs('entity.risk*')">
                    {{ __('Risk') }}
                </x-responsive-nav-link>
            @endcan
            @can('soc:investigation.view')
                <x-responsive-nav-link :href="route('investigation.index')" :active="request()->routeIs('investigation.*')">
                    {{ __('Investigations') }}
                </x-responsive-nav-link>
            @endcan
            @can('soc:response.view')
                <x-responsive-nav-link :href="route('response.index')" :active="request()->routeIs('response.*')">
                    {{ __('Response') }}
                </x-responsive-nav-link>
            @endcan
            @can('soc:report.view')
                <x-responsive-nav-link :href="route('export.index')" :active="request()->routeIs('export.*')">
                    {{ __('Export') }}
                </x-responsive-nav-link>
            @endcan
            @if (Auth::user()?->role === 'admin')
                <x-responsive-nav-link :href="route('security.hardening')" :active="request()->routeIs('security.hardening')">
                    {{ __('Security') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('resilience.index')" :active="request()->routeIs('resilience.*')">
                    {{ __('Resilience') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('operations.health')" :active="request()->routeIs('operations.*')">
                    {{ __('Operations') }}
                </x-responsive-nav-link>
            @endif
            <div class="border-t border-cyan-100/20 px-3 pt-3">
                <div class="font-medium text-base text-cyan-50">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-cyan-200/70">{{ Auth::user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit')">
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <x-responsive-nav-link :href="route('logout')"
                            onclick="event.preventDefault();
                                        this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
