<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Analyst Work Queue</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Analyst-driven collaborative workflows only. No autonomous SOC operations.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-6xl mx-auto space-y-5">
        <form method="GET" class="flex gap-3 items-center">
            <select name="analyst_id" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5">
                @foreach($analysts as $a)
                <option value="{{ $a->id }}" @selected($a->id == $analystId)>{{ $a->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm">View</button>
        </form>

        <div class="grid grid-cols-3 gap-4">
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase mb-1">Assigned Investigations</div>
                <div class="text-2xl font-bold text-cyan-300">{{ $queue['assigned_investigations']->count() }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase mb-1">Collaborating On</div>
                <div class="text-2xl font-bold text-purple-300">{{ $queue['collaborating_on'] }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase mb-1">Active Watchlists</div>
                <div class="text-2xl font-bold text-amber-300">{{ $queue['watchlist_count'] }}</div>
            </div>
        </div>

        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-2">Assigned Investigations</h3>
            @forelse($queue['assigned_investigations'] as $inv)
            <div class="flex gap-3 items-center py-1 border-b border-gray-800 text-xs">
                <span class="font-mono text-cyan-400">{{ $inv->investigation_id }}</span>
                <span class="text-gray-300">{{ $inv->title }}</span>
                <span class="px-1.5 py-0.5 rounded bg-gray-700 text-gray-400">{{ $inv->state }}</span>
                <span class="px-1.5 py-0.5 rounded {{ $inv->severity === 'critical' ? 'bg-red-900/40 text-red-300' : 'bg-gray-700 text-gray-400' }}">{{ $inv->severity }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No assigned investigations.</p>
            @endforelse
        </div>

        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-2">Recent Handoffs</h3>
            @forelse($handoffs as $h)
            <div class="text-xs flex gap-3 py-1 border-b border-gray-800">
                <span class="font-mono text-cyan-400">{{ $h->handoff_id }}</span>
                <span>{{ $h->fromAnalyst?->name ?? 'Unknown' }} → {{ $h->toAnalyst?->name ?? 'Team' }}</span>
                <span class="text-gray-500">{{ $h->unresolved_count }} unresolved</span>
                <span class="ml-auto text-gray-500">{{ $h->created_at?->format('M d H:i') }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No recent handoffs.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
