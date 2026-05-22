<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Long-Running Operations Dashboard</h2></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Operational governance workflows are advisory-only, replay-safe, and evidence-linked. No autonomous remediation, destructive operational mutation, or hidden suppression is executed.
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $stats['total_windows'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Operational Windows</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-green-300">{{ $stats['governance_pass'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Governance Pass</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['drift_critical'] > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $stats['drift_critical'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Critical Drift</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-green-300">{{ $stats['replay_acceptable'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Replay Acceptable</div>
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['trend_critical'] > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $stats['trend_critical'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Trend Critical</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-yellow-300">{{ $stats['fp_worsening'] }}</div>
                <div class="text-xs text-slate-400 mt-1">FP Worsening</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-green-300">{{ $stats['infra_stable'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Infra Stable</div>
            </div>
        </div>
    </div>
</x-app-layout>
