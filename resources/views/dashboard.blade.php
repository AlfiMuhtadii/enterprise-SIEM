<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="brand-chip">Live Telemetry</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">{{ __('Security Dashboard') }}</h2>
            </div>
            @if (auth()->user()?->role === 'admin')
                <a href="{{ route('security.alerts') }}" class="inline-flex items-center rounded-lg border border-cyan-200/30 bg-cyan-100/10 px-3 py-2 text-sm font-medium text-cyan-50 hover:bg-cyan-100/20">
                    Open alert center
                </a>
            @endif
        </div>
    </x-slot>

    <div class="grid gap-4 md:grid-cols-4">
        <div class="metric-card">
            <p class="text-sm uppercase tracking-[0.14em] text-cyan-200/75">Events</p>
            <p class="mt-2 text-3xl font-semibold text-main-ui">{{ $totals['events'] }}</p>
            <p class="mt-2 text-sm text-muted-ui">Persisted security events.</p>
        </div>
        <div class="metric-card">
            <p class="text-sm uppercase tracking-[0.14em] text-cyan-200/75">Alerts</p>
            <p class="mt-2 text-3xl font-semibold text-main-ui">{{ $totals['alerts'] }}</p>
            <p class="mt-2 text-sm text-muted-ui">Detected threats.</p>
        </div>
        <div class="metric-card">
            <p class="text-sm uppercase tracking-[0.14em] text-cyan-200/75">Responses</p>
            <p class="mt-2 text-3xl font-semibold text-main-ui">{{ $totals['responses'] }}</p>
            <p class="mt-2 text-sm text-muted-ui">Recommended actions.</p>
        </div>
        <div class="metric-card">
            <p class="text-sm uppercase tracking-[0.14em] text-cyan-200/75">Fresh Alerts</p>
            <p class="mt-2 text-3xl font-semibold text-main-ui">{{ $totals['fresh_alerts'] }}</p>
            <p class="mt-2 text-sm text-muted-ui">Last 60 minutes.</p>
        </div>
    </div>

    @if ($totals['events'] === 0 && $totals['alerts'] === 0)
        <section class="glass-card mt-4 p-6 sm:p-7">
            <h3 class="text-xl font-semibold text-main-ui">No telemetry yet</h3>
            <p class="mt-2 text-sm text-muted-ui">Run the detection flow to populate events, alerts, responses, and dashboard data.</p>
            <div class="mt-4 rounded-lg border border-cyan-200/20 bg-black/20 p-4">
                <p class="mono-ui text-sm text-cyan-100">powershell -ExecutionPolicy Bypass -File .\scripts\final-present.ps1 -SkipUp</p>
            </div>
        </section>
    @endif

    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Alert Types</h3>
            <div class="mt-4 space-y-3">
                @forelse ($topTypes as $row)
                    <div class="flex items-center justify-between rounded-lg border border-cyan-200/15 bg-black/20 px-3 py-2">
                        <span class="mono-ui text-sm text-cyan-50">{{ $row->alert_type }}</span>
                        <span class="rounded-full bg-cyan-100/10 px-2 py-1 text-sm text-cyan-100">{{ $row->total }}</span>
                    </div>
                @empty
                    <p class="text-sm text-muted-ui">No alert types available.</p>
                @endforelse
            </div>
        </section>

        <section class="glass-card overflow-hidden lg:col-span-2">
            <div class="flex items-center justify-between border-b border-cyan-100/15 px-5 py-4">
                <h3 class="text-lg font-semibold text-main-ui">Latest Alerts</h3>
                <p class="mono-ui text-xs text-cyan-200/70">limit 8</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-cyan-100/10 text-sm">
                    <thead class="bg-black/20 text-left text-xs uppercase tracking-[0.12em] text-cyan-200/70">
                        <tr>
                            <th class="px-4 py-3">Detected</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Severity</th>
                            <th class="px-4 py-3">IP</th>
                            <th class="px-4 py-3">Score</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/10">
                        @forelse ($latestAlerts as $alert)
                            <tr class="hover:bg-cyan-100/5">
                                <td class="px-4 py-3 mono-ui text-xs text-cyan-100/80">{{ $alert->detected_at }}</td>
                                <td class="px-4 py-3 mono-ui text-cyan-50">{{ $alert->alert_type }}</td>
                                <td class="px-4 py-3 text-cyan-100">{{ $alert->severity }}</td>
                                <td class="px-4 py-3 mono-ui text-cyan-100/85">{{ $alert->ip ?: '-' }}</td>
                                <td class="px-4 py-3 mono-ui text-cyan-100/85">{{ number_format((float) $alert->score, 4) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-4 py-5 text-muted-ui" colspan="5">No alerts available.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
