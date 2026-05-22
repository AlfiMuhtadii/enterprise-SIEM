<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Endpoint Telemetry Depth Dashboard</h2></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Advanced endpoint telemetry is advisory-only, replay-safe, and evidence-linked. No kernel enforcement, process injection, or destructive endpoint action is executed.
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $stats['hash_lineage_records'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Hash Lineage Records</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['unsigned_modules'] > 0 ? 'text-yellow-300' : 'text-green-300' }}">{{ $stats['unsigned_modules'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Unsigned Modules</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['evasion_critical'] > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $stats['evasion_critical'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Evasion Critical</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-yellow-300">{{ $stats['socket_anomalies'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Socket Anomalies</div>
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-yellow-300">{{ $stats['suspicious_propagation'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Suspicious Hash Propagation</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $stats['registry_persistence'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Registry Persistence Keys</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['ancestry_divergence'] > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $stats['ancestry_divergence'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Ancestry Divergence</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['confidence_degraded'] > 0 ? 'text-yellow-300' : 'text-green-300' }}">{{ $stats['confidence_degraded'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Confidence Degraded</div>
            </div>
        </div>
    </div>
</x-app-layout>
