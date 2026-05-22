<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Telemetry Continuity Timeline</h2><p class="text-xs text-amber-400/80 mt-1">Operational soak and chaos workflows are bounded, replay-safe, and advisory-only. No destructive infrastructure mutation or autonomous remediation is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Operational soak and chaos workflows are bounded, replay-safe, and advisory-only. No destructive infrastructure mutation or autonomous remediation is executed.</div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Telemetry Continuity Reports</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Report ID</th><th class="text-left py-1">Window</th><th class="text-left py-1">Expected</th><th class="text-left py-1">Observed</th><th class="text-left py-1">Continuity</th><th class="text-left py-1">Gaps</th><th class="text-left py-1">Verdict</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($reports as $r)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($r->report_id, 20) }}</td><td class="py-1">{{ $r->observation_window_minutes }}m</td><td class="py-1">{{ $r->expected_events }}</td><td class="py-1">{{ $r->observed_events }}</td><td class="py-1 {{ $r->continuity_pct >= 0.95 ? 'text-green-400' : 'text-red-400' }}">{{ number_format($r->continuity_pct * 100, 1) }}%</td><td class="py-1 {{ $r->gap_count > 0 ? 'text-yellow-400' : 'text-slate-400' }}">{{ $r->gap_count }}</td><td class="py-1"><span class="px-1 rounded {{ $r->verdict === 'pass' ? 'bg-green-800 text-green-200' : ($r->verdict === 'degraded' ? 'bg-yellow-800 text-yellow-200' : 'bg-red-800 text-red-200') }}">{{ $r->verdict }}</span></td><td class="py-1">{{ $r->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
