<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Operational Intelligence Dashboard</h2></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Operational intelligence workflows are advisory-only, replay-safe, and evidence-linked. No autonomous enforcement or destructive response is executed.
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $stats['attack_chains'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Confirmed Attack Chains</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-green-300">{{ $stats['confirmed_tp'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Confirmed True Positives</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-yellow-300">{{ $stats['drift_reports'] }}</div>
                <div class="text-xs text-slate-400 mt-1">FP Drift Reports</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['replay_drifted'] > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $stats['replay_consistent'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Replay Consistent</div>
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $stats['snapshots'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Intelligence Snapshots</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-yellow-300">{{ $stats['suppression_recs'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Suppression Recommended</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-slate-300">{{ $stats['active_views'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Active Investigation Views</div>
            </div>
        </div>
    </div>
</x-app-layout>
