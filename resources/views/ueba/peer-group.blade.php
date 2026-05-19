<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Peer Group Comparison</h2>
        <p class="text-xs text-amber-400/80 mt-1">Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.
        </div>

        <div class="flex justify-end">
            <a href="{{ route('ueba.dashboard') }}" class="text-xs text-cyan-400 hover:underline">← UEBA Dashboard</a>
        </div>

        <div class="grid grid-cols-3 gap-6">
            {{-- Group list --}}
            <div class="glass-card p-0 overflow-hidden">
                <div class="flex justify-between items-center px-4 py-3 border-b border-gray-700">
                    <span class="text-sm font-semibold text-cyan-200">Peer Groups</span>
                    <span class="text-xs text-gray-500">{{ $groups->count() }}</span>
                </div>
                <div style="max-height: 520px; overflow-y: auto;">
                    @forelse($groups as $g)
                        <a href="{{ route('ueba.peer-groups', ['peer_group_key' => $g->peer_group_key, 'group_type' => $groupType]) }}"
                           class="block border-b border-gray-800 px-4 py-2 text-xs no-underline {{ $selectedGroup?->peer_group_key === $g->peer_group_key ? 'bg-cyan-900/30' : 'hover:bg-gray-800/30' }}">
                            <div class="{{ $selectedGroup?->peer_group_key === $g->peer_group_key ? 'text-cyan-300 font-semibold' : 'text-gray-200' }}">{{ $g->group_label }}</div>
                            <div class="flex justify-between mt-0.5">
                                <span class="text-gray-500">{{ $g->group_type }}</span>
                                <span class="{{ $selectedGroup?->peer_group_key === $g->peer_group_key ? 'text-cyan-400' : 'text-gray-500' }}">{{ $g->entity_count }} members</span>
                            </div>
                        </a>
                    @empty
                        <div class="text-center text-gray-500 text-xs py-6">No peer groups computed yet.</div>
                    @endforelse
                </div>
            </div>

            {{-- Selected group detail --}}
            <div class="col-span-2">
                @if($selectedGroup)
                    <div class="glass-card p-4 space-y-4">
                        <div>
                            <h3 class="text-sm font-semibold text-cyan-200">{{ $selectedGroup->group_label }}</h3>
                            <div class="grid grid-cols-3 gap-4 text-xs mt-2">
                                <div><span class="text-gray-400">Key: </span><code class="text-cyan-400">{{ $selectedGroup->peer_group_key }}</code></div>
                                <div><span class="text-gray-400">Type: </span><span class="text-gray-200">{{ $selectedGroup->group_type }}</span></div>
                                <div><span class="text-gray-400">Members: </span><span class="text-gray-200">{{ $selectedGroup->entity_count }}</span></div>
                            </div>
                        </div>

                        @if($selectedGroup->dimension_stats)
                            <div>
                                <h4 class="text-xs font-semibold text-gray-300 mb-2">Dimension Statistics (Group Aggregates)</h4>
                                <table class="w-full text-xs border border-gray-700 rounded">
                                    <thead class="bg-gray-800/60 text-gray-400 uppercase tracking-wider">
                                        <tr>
                                            <th class="px-3 py-2 text-left">Dimension</th>
                                            <th class="px-3 py-2 text-right">Group Mean</th>
                                            <th class="px-3 py-2 text-right">Std Dev</th>
                                            <th class="px-3 py-2 text-center">Members w/ Data</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-800">
                                    @foreach($selectedGroup->dimension_stats as $dim => $stats)
                                        <tr>
                                            <td class="px-3 py-1.5"><code class="text-gray-300">{{ str_replace('_', ' ', $dim) }}</code></td>
                                            <td class="px-3 py-1.5 text-right text-gray-200">{{ number_format($stats['mean'] ?? 0, 3) }}</td>
                                            <td class="px-3 py-1.5 text-right text-gray-400">{{ number_format($stats['stddev'] ?? 0, 3) }}</td>
                                            <td class="px-3 py-1.5 text-center text-gray-500">{{ $stats['sample_count'] ?? 0 }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="text-gray-500 text-xs">Dimension statistics not yet computed for this group.</div>
                        @endif

                        @if($selectedGroup->member_entity_keys)
                            <div>
                                <h4 class="text-xs font-semibold text-gray-300 mb-2">Members <span class="text-gray-500">(up to 500)</span></h4>
                                <div class="flex flex-wrap gap-1">
                                    @foreach(array_slice($selectedGroup->member_entity_keys, 0, 50) as $key)
                                        <a href="{{ route('ueba.entity-history', ['entity_key' => $key, 'entity_type' => Str::contains($selectedGroup->group_type ?? '', 'user') ? 'user' : 'host']) }}"
                                           class="px-2 py-0.5 text-xs rounded bg-gray-700 text-gray-300 border border-gray-600 hover:bg-gray-600">
                                            {{ Str::limit($key, 28) }}
                                        </a>
                                    @endforeach
                                    @if(count($selectedGroup->member_entity_keys) > 50)
                                        <span class="px-2 py-0.5 text-xs rounded bg-gray-800 text-gray-500">+{{ count($selectedGroup->member_entity_keys) - 50 }} more</span>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="glass-card p-8 text-center text-gray-500 text-sm">Select a peer group to see its dimension statistics and members.</div>
                @endif
            </div>
        </div>

        <p class="text-xs text-gray-600 italic">Peer groups are assigned deterministically based on entity type and non-sensitive attributes. No sensitive attribute inference is performed.</p>
    </div>
</x-app-layout>
