<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Backtest Result — {{ $run->run_id }}</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Advisory only. No alerts or incidents were created by this backtest.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="glass-card p-4 grid grid-cols-3 gap-4 text-xs text-gray-300">
            <div><span class="text-gray-500">Window</span><br>{{ $run->window_start }} → {{ $run->window_end }}</div>
            <div><span class="text-gray-500">Events scanned</span><br>{{ $run->telemetry_event_count }}</div>
            <div><span class="text-gray-500">Rules tested</span><br>{{ implode(', ', $run->rule_ids) }}</div>
        </div>

        <div class="glass-card p-4 space-y-2">
            <h3 class="text-sm font-semibold text-cyan-200">Would-have-fired matches ({{ $matches->count() }})</h3>
            @forelse($matches as $match)
            <div class="text-xs text-gray-300 border-b border-gray-700/40 pb-1">
                <div class="flex justify-between">
                    <span class="font-mono text-purple-300">{{ $match->rule_id }}</span>
                    <span>actor: {{ $match->actor_key }} · {{ $match->event_count }} events</span>
                </div>
            </div>
            @empty
            <p class="text-xs text-gray-500">No matches — none of the tested rules would have fired in this window.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
