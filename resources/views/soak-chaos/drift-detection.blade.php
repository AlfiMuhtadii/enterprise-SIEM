<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Drift Detection Dashboard</h2><p class="text-xs text-amber-400/80 mt-1">Operational soak and chaos workflows are bounded, replay-safe, and advisory-only. No destructive infrastructure mutation or autonomous remediation is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Operational soak and chaos workflows are bounded, replay-safe, and advisory-only. No destructive infrastructure mutation or autonomous remediation is executed.</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $exceeded > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $exceeded }}</div>
                <div class="text-xs text-slate-400 mt-1">Drift Threshold Exceeded</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ count($byType) }}</div>
                <div class="text-xs text-slate-400 mt-1">Drift Types Tracked</div>
            </div>
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Drift Reports</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Report ID</th><th class="text-left py-1">Type</th><th class="text-left py-1">Baseline</th><th class="text-left py-1">Observed</th><th class="text-left py-1">Delta</th><th class="text-left py-1">Pct</th><th class="text-left py-1">Window</th><th class="text-left py-1">Exceeded</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($reports as $r)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($r->report_id, 16) }}</td><td class="py-1">{{ $r->drift_type }}</td><td class="py-1">{{ number_format($r->baseline_value, 2) }}</td><td class="py-1">{{ number_format($r->observed_value, 2) }}</td><td class="py-1">{{ number_format($r->drift_delta, 2) }}</td><td class="py-1 {{ abs($r->drift_pct) > 20 ? 'text-red-400' : 'text-slate-400' }}">{{ number_format($r->drift_pct, 1) }}%</td><td class="py-1">{{ $r->window_minutes }}m</td><td class="py-1"><span class="px-1 rounded {{ $r->drift_exceeds_threshold ? 'bg-red-800 text-red-200' : 'bg-green-800 text-green-200' }}">{{ $r->drift_exceeds_threshold ? 'yes' : 'no' }}</span></td><td class="py-1">{{ $r->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
