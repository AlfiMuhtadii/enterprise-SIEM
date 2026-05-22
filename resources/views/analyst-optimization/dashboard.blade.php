<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Operational Tuning Dashboard</h2></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Operational tuning workflows are advisory-only, replay-safe, and evidence-linked. No autonomous remediation, hidden suppression, or destructive alert mutation is executed.
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['overloaded_analysts'] > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $stats['overloaded_analysts'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Overloaded Analysts</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-red-300">{{ $stats['critical_alerts'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Critical Alerts</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-yellow-300">{{ $stats['repeated_dismissals'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Repeated Dismissals</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['fatigue_detected'] > 0 ? 'text-orange-300' : 'text-green-300' }}">{{ $stats['fatigue_detected'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Fatigue Detected</div>
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $stats['fp_tuning_reports'] }}</div>
                <div class="text-xs text-slate-400 mt-1">FP Tuning Reports</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-yellow-300">{{ $stats['escalation_noise'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Escalation Noise</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $stats['handoffs_validated'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Handoffs Validated</div>
            </div>
        </div>
    </div>
</x-app-layout>
