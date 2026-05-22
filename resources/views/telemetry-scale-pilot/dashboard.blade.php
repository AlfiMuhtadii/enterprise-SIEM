<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Telemetry Scale Pilot Dashboard</h2></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Scale pilot workflows are bounded, replay-safe, and advisory-only. No autonomous remediation, destructive infrastructure mutation, or uncontrolled telemetry onboarding is executed.
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $stats['scale_runs'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Scale Runs</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-green-300">{{ $stats['passed_runs'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Passed</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['drift_critical'] > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $stats['drift_critical'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Critical Drift</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $stats['active_windows'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Active Windows</div>
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-green-300">{{ $stats['recovery_successful'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Recovery Successful</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-green-300">{{ $stats['pressure_bounded'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Pressure Bounded</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-green-300">{{ $stats['workload_stable'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Workload Stable</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-green-300">{{ $stats['queue_recovered'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Queue Recovered</div>
            </div>
        </div>
    </div>
</x-app-layout>
