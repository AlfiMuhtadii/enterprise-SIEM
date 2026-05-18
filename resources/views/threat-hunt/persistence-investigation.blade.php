<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Persistence Investigation</h2>
                <p class="text-xs text-amber-400/80 mt-1 font-medium">Threat hunting is advisory-only and non-destructive.</p>
            </div>
            <a href="{{ route('threat-hunt.dashboard') }}" class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">← Dashboard</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="glass-card p-4">
                <form method="GET" action="{{ route('threat-hunt.persistence-investigation') }}" class="flex gap-3 items-end">
                    <div class="flex-1">
                        <label class="block text-xs text-cyan-400/70 mb-1">Select Agent</label>
                        <select name="agent_id" class="w-full text-sm px-3 py-2 rounded bg-black/30 border border-cyan-200/20 text-cyan-200">
                            <option value="">— Select —</option>
                            @foreach ($agents as $agent)
                                <option value="{{ $agent->agent_id }}" {{ $agentId === $agent->agent_id ? 'selected' : '' }}>
                                    {{ $agent->hostname ?? $agent->agent_id }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="px-4 py-2 rounded bg-cyan-700/30 border border-cyan-400/30 text-cyan-200 hover:bg-cyan-700/50 text-sm">Investigate</button>
                </form>
            </div>

            @if ($agentId)
                @php $newItems = array_filter($items, fn($i) => $i['is_new'] ?? false); @endphp
                @if (!empty($newItems))
                <div class="glass-card p-4 border border-yellow-400/30">
                    <p class="text-xs text-yellow-300">{{ count($newItems) }} new persistence item(s) detected since last snapshot. Requires analyst review.</p>
                </div>
                @endif
                <div class="glass-card p-6">
                    <h3 class="text-sm font-semibold text-cyan-200 mb-4">Persistence Items ({{ count($items) }})</h3>
                    @if (empty($items))
                        <p class="text-cyan-400/50 text-sm">No persistence items recorded for this agent.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm text-cyan-200/80">
                                <thead>
                                    <tr class="text-left text-xs text-cyan-400/60 border-b border-cyan-200/10">
                                        <th class="pb-2 pr-4">Type</th><th class="pb-2 pr-4">Name</th>
                                        <th class="pb-2 pr-4">Path</th><th class="pb-2 pr-4">First Seen</th>
                                        <th class="pb-2">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($items as $item)
                                    <tr class="border-b border-cyan-200/5 hover:bg-cyan-100/5 {{ $item['is_new'] ? 'bg-yellow-900/10' : '' }}">
                                        <td class="py-2 pr-4 text-xs text-cyan-400/70">{{ $item['item_type'] }}</td>
                                        <td class="py-2 pr-4 font-mono text-xs">{{ $item['item_name'] }}</td>
                                        <td class="py-2 pr-4 text-xs text-cyan-400/50 truncate max-w-xs">{{ $item['item_path'] ?? '—' }}</td>
                                        <td class="py-2 pr-4 text-xs text-cyan-400/60">{{ $item['first_seen_at'] ? \Carbon\Carbon::parse($item['first_seen_at'])->diffForHumans() : '—' }}</td>
                                        <td class="py-2 text-xs">
                                            @if ($item['is_new']) <span class="px-1.5 py-0.5 rounded bg-yellow-900/40 text-yellow-300">new</span>
                                            @else <span class="px-1.5 py-0.5 rounded bg-cyan-900/40 text-cyan-400">known</span>
                                            @endif
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif

            <div class="glass-card p-4 border border-amber-400/20">
                <p class="text-xs text-amber-300/80">Advisory-only. No persistence removal, service disabling, or file deletion is possible from this interface.</p>
            </div>
        </div>
    </div>
</x-app-layout>
