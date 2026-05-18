<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="brand-chip">Shadow Telemetry</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">Endpoint Inventory</h2>
            </div>
            <span class="text-xs uppercase tracking-[0.14em] text-cyan-100/50">shadow-only · no active promotion</span>
        </div>
    </x-slot>

    <div class="flex gap-6">
        @include('endpoint._sidebar')

        <div class="min-w-0 flex-1 space-y-5">

            {{-- Health summary --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                @foreach ([
                    ['label' => 'Total Agents',    'value' => $stats['total'],         'color' => 'text-cyan-50'],
                    ['label' => 'Online',          'value' => $stats['online'],        'color' => 'text-green-300'],
                    ['label' => 'Offline',         'value' => $stats['offline'],       'color' => 'text-red-300/70'],
                    ['label' => 'Integrity Fail',  'value' => $stats['integrity_fail'],'color' => 'text-orange-300'],
                    ['label' => 'Shadow Alerts',   'value' => $stats['shadow_alerts'], 'color' => 'text-yellow-300'],
                    ['label' => 'Critical Hosts',  'value' => $stats['critical_hosts'],'color' => 'text-red-300'],
                ] as $m)
                    <div class="glass-card px-4 py-3">
                        <p class="text-xs text-cyan-200/45">{{ $m['label'] }}</p>
                        <p class="mt-1 text-xl font-semibold {{ $m['color'] }}">{{ $m['value'] }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Filter bar --}}
            <div class="glass-card p-4">
                <form method="GET" action="{{ route('endpoint.index') }}" class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="mb-1 block text-xs text-cyan-200/55">Search</label>
                        <input type="text" name="q" value="{{ $search }}"
                               placeholder="agent_id or host_id…"
                               class="rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-1.5 text-sm text-cyan-50 placeholder-cyan-200/25 focus:outline-none mono-ui w-64">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-cyan-200/55">Status</label>
                        <select name="status" class="rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-1.5 text-sm text-cyan-50 focus:outline-none">
                            <option value="">All</option>
                            <option value="online" @selected($statusFilter === 'online')>Online</option>
                            <option value="offline" @selected($statusFilter === 'offline')>Offline</option>
                        </select>
                    </div>
                    <button type="submit" class="rounded-lg border border-cyan-400/40 bg-cyan-500/15 px-4 py-1.5 text-sm text-cyan-200 hover:bg-cyan-500/25 transition">Filter</button>
                    @if ($search || $statusFilter)
                        <a href="{{ route('endpoint.index') }}" class="self-center text-xs text-cyan-200/40 hover:text-cyan-200 transition">Clear</a>
                    @endif
                </form>
            </div>

            {{-- Agent inventory table --}}
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3 flex items-center justify-between">
                    <span class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">{{ $agents->count() }} agents</span>
                    <span class="text-xs text-cyan-200/30">Shadow detection only — alerts never enter active incident pipeline</span>
                </div>
                @if ($agents->isEmpty())
                    <div class="p-8 text-center text-sm text-cyan-200/40">No agents enrolled. Deploy the endpoint agent and register with the SOC.</div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.08em] text-cyan-200/35">
                                <th class="px-5 py-3">Agent / Host</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Version</th>
                                <th class="px-4 py-3">Last Heartbeat</th>
                                <th class="px-4 py-3">Shadow Alerts</th>
                                <th class="px-4 py-3">Integrity</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyan-100/5">
                            @foreach ($agents as $agent)
                                @php
                                    $ac = $alertCounts->get($agent->host_id)?->alert_count ?? 0;
                                    $statusColor = $agent->status === 'online'
                                        ? 'border-green-400/40 bg-green-500/10 text-green-200'
                                        : 'border-red-400/30 bg-red-500/10 text-red-200/60';
                                @endphp
                                <tr class="hover:bg-cyan-100/3 transition">
                                    <td class="px-5 py-3">
                                        <p class="mono-ui text-xs text-cyan-200/80">{{ $agent->agent_id }}</p>
                                        <p class="mt-0.5 text-xs text-cyan-200/45">{{ $agent->host_id }}</p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="rounded border px-1.5 py-0.5 text-xs {{ $statusColor }}">{{ $agent->status }}</span>
                                    </td>
                                    <td class="px-4 py-3 mono-ui text-xs text-cyan-200/60">{{ $agent->agent_version ?? '—' }}</td>
                                    <td class="px-4 py-3 text-xs text-cyan-200/55">
                                        {{ $agent->last_seen_at ? \Carbon\Carbon::parse($agent->last_seen_at)->diffForHumans() : '—' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($ac > 0)
                                            <span class="rounded border border-yellow-400/30 bg-yellow-500/10 px-2 py-0.5 text-xs text-yellow-200">{{ $ac }}</span>
                                        @else
                                            <span class="text-xs text-cyan-200/30">0</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if (!$agent->integrity_ok)
                                            <span class="text-xs text-red-300">✗ fail</span>
                                        @else
                                            <span class="text-xs text-green-300/60">✓</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('endpoint.show', $agent->agent_id) }}"
                                           class="text-xs text-cyan-400 hover:text-cyan-200 transition">Timeline →</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- Telemetry volume (last 7 days) --}}
            @if ($telemetryVolume->isNotEmpty())
            <div class="glass-card p-5">
                <h3 class="mb-4 text-xs uppercase tracking-[0.12em] text-cyan-200/50">Endpoint Pipeline Events (7 days)</h3>
                <div class="flex items-end gap-2">
                    @foreach ($telemetryVolume->reverse() as $row)
                        @php $max = $telemetryVolume->max('cnt') ?: 1; $h = max(4, (int) (($row->cnt / $max) * 60)); @endphp
                        <div class="flex flex-col items-center gap-1">
                            <span class="text-xs text-cyan-200/40">{{ $row->cnt }}</span>
                            <div class="w-8 rounded-t bg-cyan-500/30" style="height: {{ $h }}px"></div>
                            <span class="text-xs text-cyan-200/35">{{ \Carbon\Carbon::parse($row->day)->format('m/d') }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
            @endif

        </div>
    </div>
</x-app-layout>
