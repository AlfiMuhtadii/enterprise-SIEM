<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Policy Assignment View</h2>
        <p class="text-xs text-amber-400/80 mt-1">Endpoint operations are advisory-only. No autonomous containment or enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Endpoint operations are advisory-only. No autonomous containment or enforcement is executed.
        </div>
        <div class="flex justify-end">
            <a href="{{ route('endpoint-fleet.dashboard') }}" class="text-xs text-cyan-400 hover:underline">← Fleet Dashboard</a>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <div class="glass-card p-0 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-700 text-sm font-semibold text-cyan-200">Fleet Policies</div>
                <div style="max-height: 480px; overflow-y: auto;">
                    @forelse($policies as $p)
                        <a href="{{ route('endpoint-fleet.policies', ['policy_id' => $p->policy_id]) }}"
                           class="block border-b border-gray-800 px-4 py-2 text-xs no-underline {{ $selectedPolicy?->policy_id === $p->policy_id ? 'bg-cyan-900/30' : 'hover:bg-gray-800/30' }}">
                            <div class="{{ $selectedPolicy?->policy_id === $p->policy_id ? 'text-cyan-300 font-semibold' : 'text-gray-200' }}">{{ $p->name }}</div>
                            <div class="flex justify-between mt-0.5 text-gray-500">
                                <span>v{{ $p->policy_version }}</span>
                                <span>{{ $p->assigned_agent_count }} agents</span>
                                <span class="{{ $p->is_active ? 'text-green-400' : 'text-gray-600' }}">{{ $p->is_active ? 'active' : 'inactive' }}</span>
                            </div>
                        </a>
                    @empty
                        <div class="text-center text-gray-500 text-xs py-6">No fleet policies defined.</div>
                    @endforelse
                </div>
            </div>

            <div class="col-span-2">
                @if($selectedPolicy)
                    <div class="glass-card p-4 space-y-4">
                        <div>
                            <h3 class="text-sm font-semibold text-cyan-200">{{ $selectedPolicy->name }}</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-xs mt-2">
                                <div><span class="text-gray-400">Version: </span><span class="text-gray-200">{{ $selectedPolicy->policy_version }}</span></div>
                                <div><span class="text-gray-400">Agents: </span><span class="text-gray-200">{{ $selectedPolicy->assigned_agent_count }}</span></div>
                                <div><span class="text-gray-400">Rollback: </span><span class="text-gray-200">{{ $selectedPolicy->rollback_supported ? 'yes' : 'no' }}</span></div>
                            </div>
                            @if($selectedPolicy->description)
                                <p class="text-gray-400 text-xs mt-2">{{ $selectedPolicy->description }}</p>
                            @endif
                        </div>

                        <div>
                            <h4 class="text-xs font-semibold text-gray-300 mb-2">Recent Assignments</h4>
                            @forelse($assignments as $a)
                                <div class="flex justify-between items-center py-1 border-b border-gray-800 text-xs">
                                    <span class="text-cyan-400">{{ $a->agent?->hostname ?? $a->agent_id }}</span>
                                    <span class="text-gray-400">{{ $a->assignment_reason }}</span>
                                    <span class="{{ $a->applied_to_agent ? 'text-green-400' : 'text-gray-500' }}">{{ $a->applied_to_agent ? 'applied' : 'pending' }}</span>
                                    <span class="text-gray-500">{{ \Carbon\Carbon::parse($a->assigned_at)->diffForHumans() }}</span>
                                </div>
                            @empty
                                <p class="text-gray-500 text-xs">No assignments for this policy.</p>
                            @endforelse
                        </div>
                    </div>
                @else
                    <div class="glass-card p-8 text-center text-gray-500 text-sm">Select a fleet policy to see its assignment history.</div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
