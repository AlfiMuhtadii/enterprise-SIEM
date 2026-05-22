<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Sensor Health Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1">Sensor governance workflows are advisory-only and replay-safe. No autonomous remediation, kernel enforcement, or destructive endpoint action is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Sensor governance workflows are advisory-only and replay-safe. No autonomous remediation, kernel enforcement, or destructive endpoint action is executed.
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['critical_resource_pressure'] > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $stats['critical_resource_pressure'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Critical Resource Pressure</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['unhealthy_collector_events'] > 0 ? 'text-yellow-300' : 'text-green-300' }}">{{ $stats['unhealthy_collector_events'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Unhealthy Collector Events</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['unrecovered_gaps'] > 0 ? 'text-red-300' : 'text-slate-400' }}">{{ $stats['unrecovered_gaps'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Unrecovered Gaps</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['pkg_validation_failures'] > 0 ? 'text-red-300' : 'text-slate-400' }}">{{ $stats['pkg_validation_failures'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Pkg Validation Failures</div>
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $stats['total_resource_snapshots'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Resource Snapshots</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['integrity_failures'] > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $stats['integrity_failures'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Integrity Failures</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['crash_induced_restarts'] > 0 ? 'text-orange-300' : 'text-slate-400' }}">{{ $stats['crash_induced_restarts'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Crash Restarts</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['sequence_failures'] > 0 ? 'text-yellow-300' : 'text-green-300' }}">{{ $stats['sequence_failures'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Sequence Failures</div>
            </div>
        </div>
    </div>
</x-app-layout>
