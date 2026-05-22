<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Long-Duration Soak Dashboard</h2><p class="text-xs text-amber-400/80 mt-1">Operational soak and chaos workflows are bounded, replay-safe, and advisory-only. No destructive infrastructure mutation or autonomous remediation is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Operational soak and chaos workflows are bounded, replay-safe, and advisory-only. No destructive infrastructure mutation or autonomous remediation is executed.</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $stats['total_soak_runs'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Total Soak Runs</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-green-300">{{ $stats['soak_runs_passed'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Passed</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['soak_runs_failed'] > 0 ? 'text-red-300' : 'text-slate-400' }}">{{ $stats['soak_runs_failed'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Failed</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['drift_exceeded'] > 0 ? 'text-orange-300' : 'text-slate-400' }}">{{ $stats['drift_exceeded'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Drift Exceeded</div>
            </div>
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Recent Soak Runs</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Run ID</th><th class="text-left py-1">Type</th><th class="text-left py-1">Duration</th><th class="text-left py-1">Status</th><th class="text-left py-1">Passed</th><th class="text-left py-1">Mem MB</th><th class="text-left py-1">Gap Rate</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($recent as $r)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($r->run_id, 20) }}</td><td class="py-1 capitalize">{{ $r->soak_type }}</td><td class="py-1">{{ $r->duration_minutes }}m</td><td class="py-1 capitalize">{{ $r->status }}</td><td class="py-1"><span class="px-1 rounded {{ $r->passed ? 'bg-green-800 text-green-200' : 'bg-red-800 text-red-200' }}">{{ $r->passed ? 'yes' : 'no' }}</span></td><td class="py-1 {{ $r->memory_growth_mb > 256 ? 'text-orange-400' : 'text-slate-400' }}">{{ number_format($r->memory_growth_mb, 1) }}</td><td class="py-1 {{ $r->telemetry_gap_rate > 0.05 ? 'text-red-400' : 'text-slate-400' }}">{{ number_format($r->telemetry_gap_rate * 100, 2) }}%</td><td class="py-1">{{ $r->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
