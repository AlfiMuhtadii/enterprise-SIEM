<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Live Pilot Dashboard</h2></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Pilot execution workflows are bounded, replay-safe, and advisory-only. No autonomous remediation, destructive rollback, or unrestricted onboarding is executed.
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $stats['active_pilots'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Active Pilots</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-green-300">{{ $stats['total_enrollments'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Enrolled Endpoints</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['health_fail'] > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $stats['health_ok'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Health OK</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-yellow-300">{{ $stats['rollback_pending'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Rollback Pending</div>
            </div>
        </div>
    </div>
</x-app-layout>
