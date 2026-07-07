<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Detection Backtest</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Replay-safe, advisory only. Never writes to security_alerts/security_incidents.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Runs a bounded set of identity/cloud/SaaS rules against retained normalized telemetry over a trailing window and reports a "would-have-fired" count + sample matches — before committing a rule to a live soak.
        </div>

        <div class="glass-card p-4 space-y-3">
            <h3 class="text-sm font-semibold text-purple-200">Run a backtest</h3>
            <form method="POST" action="{{ route('detection.backtest.store') }}" class="space-y-3">
                @csrf
                <div class="grid grid-cols-2 gap-2 text-xs">
                    @foreach($supportedRuleIds as $ruleId)
                    <label class="flex items-center gap-2 text-gray-300">
                        <input type="checkbox" name="rule_ids[]" value="{{ $ruleId }}">
                        <span class="font-mono">{{ $ruleId }}</span>
                    </label>
                    @endforeach
                </div>
                <div class="flex items-center gap-3">
                    <label class="text-xs text-gray-400">Trailing days</label>
                    <input type="number" name="days" value="7" min="1" max="90" class="rounded bg-gray-800 border-gray-700 text-xs text-gray-200 w-20">
                    <button type="submit" class="rounded bg-cyan-700/40 hover:bg-cyan-700/60 text-cyan-100 text-xs px-3 py-2">Run backtest</button>
                </div>
            </form>
        </div>

        <div class="glass-card p-4 space-y-2">
            <h3 class="text-sm font-semibold text-cyan-200">Recent runs</h3>
            @forelse($runs as $run)
            <a href="{{ route('detection.backtest.show', $run->run_id) }}" class="flex justify-between text-xs text-gray-300 border-b border-gray-700/40 pb-1 hover:text-cyan-300">
                <span class="font-mono text-purple-300">{{ $run->run_id }}</span>
                <span>{{ $run->window_start->toDateString() }} → {{ $run->window_end->toDateString() }} · {{ $run->telemetry_event_count }} events scanned</span>
            </a>
            @empty
            <p class="text-xs text-gray-500">No backtests run yet.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
