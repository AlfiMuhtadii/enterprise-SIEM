<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Persistence Inventory</h2>
                <p class="text-xs text-amber-400/80 mt-1 font-medium">Shadow-only endpoint visibility. No active containment.</p>
                <p class="text-xs text-cyan-400/50 mt-0.5">{{ $agent->hostname ?? $agent->agent_id }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('endpoint.behavioral.process-network', $agent->agent_id) }}"
                   class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">Network</a>
                <a href="{{ route('endpoint.behavioral.activity', $agent->agent_id) }}"
                   class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">← Timeline</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @php
                $groupedItems = collect($items)->groupBy('item_type');
                $typeLabels = ['systemd_service' => 'Systemd Services', 'cron_job' => 'Cron Jobs', 'startup_script' => 'Startup Scripts'];
            @endphp

            @if (empty($items))
                <div class="glass-card p-6">
                    <p class="text-cyan-400/50 text-sm">No persistence inventory available. The agent has not submitted a behavioral snapshot with persistence data yet.</p>
                </div>
            @else
                @foreach ($groupedItems as $type => $typeItems)
                <div class="glass-card p-6">
                    <h3 class="text-sm font-semibold text-cyan-200 mb-4">
                        {{ $typeLabels[$type] ?? ucfirst(str_replace('_', ' ', $type)) }}
                        <span class="ml-2 text-xs text-cyan-400/50">({{ count($typeItems) }})</span>
                    </h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-cyan-200/80">
                            <thead>
                                <tr class="text-left text-xs text-cyan-400/60 border-b border-cyan-200/10">
                                    <th class="pb-2 pr-4">Name</th>
                                    <th class="pb-2 pr-4">Path</th>
                                    <th class="pb-2 pr-4">First Seen</th>
                                    <th class="pb-2 pr-4">Last Seen</th>
                                    <th class="pb-2">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($typeItems as $item)
                                <tr class="border-b border-cyan-200/5 hover:bg-cyan-100/5 {{ $item['is_new'] ? 'bg-yellow-900/10' : '' }}">
                                    <td class="py-2 pr-4 font-mono text-xs font-medium">{{ $item['item_name'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-xs text-cyan-400/60 truncate max-w-xs">{{ $item['item_path'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-xs text-cyan-400/60">{{ $item['first_seen_at'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-xs text-cyan-400/60">{{ $item['last_seen_at'] ?? '—' }}</td>
                                    <td class="py-2">
                                        @if ($item['is_new'])
                                            <span class="px-2 py-0.5 rounded text-xs bg-yellow-900/40 text-yellow-300">new</span>
                                        @else
                                            <span class="px-2 py-0.5 rounded text-xs bg-cyan-900/40 text-cyan-400">known</span>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @endforeach
            @endif

            <div class="glass-card p-4 border border-amber-400/20">
                <p class="text-xs text-amber-300/80">
                    Shadow-only persistence inventory. Visibility only — no service modification, disabling, or deletion is possible from this interface.
                    New items highlighted in yellow are newly observed since the previous snapshot.
                </p>
            </div>

        </div>
    </div>
</x-app-layout>
