<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="brand-chip">Detection Center</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">Security Alerts</h2>
            </div>
            <form method="GET" action="{{ route('security.alerts') }}" class="flex items-center gap-2">
                <label for="minutes" class="text-sm text-cyan-100/75">Window</label>
                <select id="minutes" name="minutes" class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50 focus:border-cyan-200 focus:ring-cyan-200">
                    @foreach ([15, 30, 60, 240, 1440] as $option)
                        <option value="{{ $option }}" @selected($minutes === $option)>{{ $option }}m</option>
                    @endforeach
                </select>
                <button class="rounded-lg border border-cyan-200/30 bg-cyan-100/10 px-3 py-2 text-sm font-medium text-cyan-50 hover:bg-cyan-100/20">
                    Apply
                </button>
            </form>
        </div>
    </x-slot>

    <div class="grid gap-4 md:grid-cols-4">
        <div class="metric-card">
            <p class="text-sm uppercase tracking-[0.14em] text-cyan-200/75">Alerts</p>
            <p class="mt-2 text-3xl font-semibold text-main-ui">{{ $totals['alerts'] }}</p>
            <p class="mt-2 text-sm text-muted-ui">Latest records in selected window.</p>
        </div>
        <div class="metric-card">
            <p class="text-sm uppercase tracking-[0.14em] text-cyan-200/75">Responses</p>
            <p class="mt-2 text-3xl font-semibold text-main-ui">{{ $totals['responses'] }}</p>
            <p class="mt-2 text-sm text-muted-ui">Recommended or executed actions.</p>
        </div>
        <div class="metric-card">
            <p class="text-sm uppercase tracking-[0.14em] text-cyan-200/75">Types</p>
            <p class="mt-2 text-3xl font-semibold text-main-ui">{{ $totals['types'] }}</p>
            <p class="mt-2 text-sm text-muted-ui">Distinct detection categories.</p>
        </div>
        <div class="metric-card">
            <p class="text-sm uppercase tracking-[0.14em] text-cyan-200/75">Source IPs</p>
            <p class="mt-2 text-3xl font-semibold text-main-ui">{{ $totals['ips'] }}</p>
            <p class="mt-2 text-sm text-muted-ui">Top active alert sources.</p>
        </div>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Alert Types</h3>
            <div class="mt-4 space-y-3">
                @forelse ($summary as $row)
                    <div class="flex items-center justify-between rounded-lg border border-cyan-200/15 bg-black/20 px-3 py-2">
                        <span class="mono-ui text-sm text-cyan-50">{{ $row->alert_type }}</span>
                        <span class="rounded-full bg-cyan-100/10 px-2 py-1 text-sm text-cyan-100">{{ $row->total }}</span>
                    </div>
                @empty
                    <p class="text-sm text-muted-ui">No alert types in this window.</p>
                @endforelse
            </div>
        </section>

        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Severity</h3>
            <div class="mt-4 space-y-3">
                @forelse ($severity as $row)
                    <div class="flex items-center justify-between rounded-lg border border-cyan-200/15 bg-black/20 px-3 py-2">
                        <span class="capitalize text-sm text-cyan-50">{{ $row->severity ?: 'unknown' }}</span>
                        <span class="rounded-full bg-cyan-100/10 px-2 py-1 text-sm text-cyan-100">{{ $row->total }}</span>
                    </div>
                @empty
                    <p class="text-sm text-muted-ui">No severity data in this window.</p>
                @endforelse
            </div>
        </section>

        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Top IPs</h3>
            <div class="mt-4 space-y-3">
                @forelse ($topIps as $row)
                    <div class="rounded-lg border border-cyan-200/15 bg-black/20 px-3 py-2">
                        <div class="flex items-center justify-between gap-3">
                            <span class="mono-ui text-sm text-cyan-50">{{ $row->ip }}</span>
                            <span class="rounded-full bg-cyan-100/10 px-2 py-1 text-sm text-cyan-100">{{ $row->total }}</span>
                        </div>
                        <p class="mt-1 text-xs text-muted-ui">max score {{ number_format((float) $row->max_score, 4) }}</p>
                    </div>
                @empty
                    <p class="text-sm text-muted-ui">No source IPs in this window.</p>
                @endforelse
            </div>
        </section>
    </div>

    <section class="glass-card mt-4 overflow-hidden">
        <div class="flex items-center justify-between border-b border-cyan-100/15 px-5 py-4">
            <h3 class="text-lg font-semibold text-main-ui">Latest Alerts</h3>
            <p class="mono-ui text-xs text-cyan-200/70">limit 80</p>
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
                        <th class="px-4 py-3">Model</th>
                        <th class="px-4 py-3">ATT&amp;CK</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-cyan-100/10">
                    @forelse ($alerts as $alert)
                        <tr class="hover:bg-cyan-100/5">
                            <td class="px-4 py-3 mono-ui text-xs text-cyan-100/80">{{ $alert->detected_at }}</td>
                            <td class="px-4 py-3 mono-ui text-cyan-50">{{ $alert->alert_type }}</td>
                            <td class="px-4 py-3 text-cyan-100">{{ $alert->severity }}</td>
                            <td class="px-4 py-3 mono-ui text-cyan-100/85">{{ $alert->ip ?: '-' }}</td>
                            <td class="px-4 py-3 mono-ui text-cyan-100/85">{{ number_format((float) $alert->score, 4) }}</td>
                            <td class="px-4 py-3 text-cyan-100/75">{{ $alert->model_label ?: '-' }}</td>
                            <td class="px-4 py-3 text-xs">
                                @if ($alert->mitre_technique_id)
                                    <span class="mono-ui text-cyan-300">{{ $alert->mitre_technique_id }}</span>
                                    <span class="block text-cyan-100/60 mt-0.5">{{ $alert->mitre_tactic }}</span>
                                @elseif (isset($mitreMap[$alert->alert_type]))
                                    <span class="mono-ui text-cyan-300">{{ $mitreMap[$alert->alert_type]['technique_id'] }}</span>
                                    <span class="block text-cyan-100/60 mt-0.5">{{ $mitreMap[$alert->alert_type]['tactic'] }}</span>
                                @else
                                    <span class="text-cyan-100/30">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-5 text-muted-ui" colspan="7">No alerts in this window.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="glass-card mt-4 overflow-hidden">
        <div class="flex items-center justify-between border-b border-cyan-100/15 px-5 py-4">
            <h3 class="text-lg font-semibold text-main-ui">Security Responses</h3>
            <p class="mono-ui text-xs text-cyan-200/70">limit 40</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-cyan-100/10 text-sm">
                <thead class="bg-black/20 text-left text-xs uppercase tracking-[0.12em] text-cyan-200/70">
                    <tr>
                        <th class="px-4 py-3">Created</th>
                        <th class="px-4 py-3">Action</th>
                        <th class="px-4 py-3">Target</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Reason</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-cyan-100/10">
                    @forelse ($responses as $response)
                        <tr class="hover:bg-cyan-100/5">
                            <td class="px-4 py-3 mono-ui text-xs text-cyan-100/80">{{ $response->created_at_event }}</td>
                            <td class="px-4 py-3 mono-ui text-cyan-50">{{ $response->action_type }}</td>
                            <td class="px-4 py-3 mono-ui text-cyan-100/85">{{ $response->target_type }}:{{ $response->target_id }}</td>
                            <td class="px-4 py-3 text-cyan-100">{{ $response->status }}</td>
                            <td class="px-4 py-3 text-cyan-100/75">{{ $response->reason }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-5 text-muted-ui" colspan="5">No responses in this window.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="glass-card mt-4 overflow-hidden">
        <div class="flex items-center justify-between border-b border-cyan-100/15 px-5 py-4">
            <h3 class="text-lg font-semibold text-main-ui">Active Response Policy</h3>
            <p class="mono-ui text-xs text-cyan-200/70">auto enforcement state</p>
        </div>
        <div class="grid gap-4 p-5 lg:grid-cols-3">
            @foreach ($policyEntries as $action => $entries)
                <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <h4 class="mono-ui text-sm text-cyan-50">{{ $action }}</h4>
                        <span class="rounded-full bg-cyan-100/10 px-2 py-1 text-sm text-cyan-100">{{ count($entries) }}</span>
                    </div>
                    <div class="mt-3 space-y-2">
                        @forelse ($entries as $target => $record)
                            <div class="rounded-md border border-cyan-100/10 bg-slate-950/40 p-2">
                                <p class="mono-ui break-all text-xs text-cyan-100">{{ $target }}</p>
                                <p class="mt-1 text-xs text-cyan-100/70">{{ $record['expires_at'] ?? 'no expiry' }}</p>
                                <p class="mt-1 text-xs text-muted-ui">{{ $record['reason'] ?? '' }}</p>
                            </div>
                        @empty
                            <p class="text-sm text-muted-ui">No active entries.</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</x-app-layout>
