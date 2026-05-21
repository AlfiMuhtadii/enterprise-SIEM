<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Deployment Safety Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1">Release governance workflows are approval-gated and replay-safe. No autonomous deployment, destructive rollback, or hidden environment mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> Release governance workflows are approval-gated and replay-safe. No autonomous deployment, destructive rollback, or hidden environment mutation is executed.
        </div>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $driftCount > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $driftCount }}</div>
                <div class="text-xs text-slate-400 mt-1">Blocking Drifts</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-green-300">{{ $goCount }}</div>
                <div class="text-xs text-slate-400 mt-1">Go Decisions</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $noGoCount > 0 ? 'text-red-300' : 'text-slate-400' }}">{{ $noGoCount }}</div>
                <div class="text-xs text-slate-400 mt-1">No-Go Decisions</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $pendingCount > 0 ? 'text-yellow-300' : 'text-slate-400' }}">{{ $pendingCount }}</div>
                <div class="text-xs text-slate-400 mt-1">Pending Approvals</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $readyRollback }}</div>
                <div class="text-xs text-slate-400 mt-1">Ready Rollbacks</div>
            </div>
        </div>
    </div>
</x-app-layout>
