<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Operational Stability Dashboard</h2><p class="text-xs text-amber-400/80 mt-1">Operational soak and chaos workflows are bounded, replay-safe, and advisory-only. No destructive infrastructure mutation or autonomous remediation is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Operational soak and chaos workflows are bounded, replay-safe, and advisory-only. No destructive infrastructure mutation or autonomous remediation is executed.</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['soak_runs_failed'] > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $stats['soak_runs_passed'] }}/{{ $stats['total_soak_runs'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Soak Pass Rate</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['chaos_fail'] > 0 ? 'text-orange-300' : 'text-green-300' }}">{{ $stats['chaos_pass'] }}/{{ $stats['chaos_runs_total'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Chaos Pass Rate</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['drift_exceeded'] > 0 ? 'text-yellow-300' : 'text-green-300' }}">{{ $stats['drift_exceeded'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Drift Exceeded</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $stats['enabled_scenarios'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Enabled Scenarios</div>
            </div>
        </div>
        @if($drifts->count() > 0)
        <div class="rounded border border-orange-700/30 bg-orange-900/10 p-4">
            <h3 class="text-sm font-semibold text-orange-300 mb-2">Drift Threshold Exceeded</h3>
            @foreach($drifts as $d)
            <div class="text-xs text-slate-300 py-1 border-b border-slate-800">{{ $d->drift_type }} — {{ number_format($d->drift_pct, 1) }}% drift over {{ $d->window_minutes }}m window</div>
            @endforeach
        </div>
        @endif
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Bounded Failure Scenarios</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Key</th><th class="text-left py-1">Name</th><th class="text-left py-1">Component</th><th class="text-left py-1">Max Duration</th><th class="text-left py-1">Enabled</th><th class="text-left py-1">Approval</th><th class="text-left py-1">Destructive</th></tr></thead>
                <tbody>@foreach($scenarios as $s)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ $s->scenario_key }}</td><td class="py-1">{{ $s->name }}</td><td class="py-1">{{ $s->component }}</td><td class="py-1">{{ $s->max_duration_seconds }}s</td><td class="py-1 {{ $s->enabled ? 'text-green-400' : 'text-slate-500' }}">{{ $s->enabled ? 'yes' : 'no' }}</td><td class="py-1 {{ $s->requires_approval ? 'text-yellow-400' : 'text-slate-400' }}">{{ $s->requires_approval ? 'required' : 'not req.' }}</td><td class="py-1 {{ $s->destructive ? 'text-red-400 font-bold' : 'text-green-400' }}">{{ $s->destructive ? 'YES' : 'no' }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
