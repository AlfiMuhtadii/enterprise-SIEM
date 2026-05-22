<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Operator Readiness Dashboard</h2><p class="text-xs text-amber-400/80 mt-1">Pilot governance workflows are bounded, replay-safe, and approval-gated. No autonomous deployment, destructive rollback, or unrestricted telemetry onboarding is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Pilot governance workflows are bounded, replay-safe, and approval-gated. No autonomous deployment, destructive rollback, or unrestricted telemetry onboarding is executed.</div>
        <div class="grid grid-cols-2 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center"><div class="text-2xl font-bold text-green-300">{{ $stats['ready'] }}</div><div class="text-xs text-slate-400 mt-1">Operators Ready</div></div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center"><div class="text-2xl font-bold {{ $stats['not_ready'] > 0 ? 'text-yellow-300' : 'text-slate-400' }}">{{ $stats['not_ready'] }}</div><div class="text-xs text-slate-400 mt-1">Not Ready</div></div>
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Readiness Reviews</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Review ID</th><th class="text-left py-1">Operator</th><th class="text-left py-1">Type</th><th class="text-left py-1">Verdict</th><th class="text-left py-1">Runbook</th><th class="text-left py-1">Escalation</th><th class="text-left py-1">Handoff</th><th class="text-left py-1">Incident</th><th class="text-left py-1">Ack (s)</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($reviews as $r)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($r->review_id, 16) }}</td><td class="py-1">{{ $r->operator_id }}</td><td class="py-1">{{ $r->review_type }}</td><td class="py-1"><span class="px-1 rounded {{ $r->verdict === 'pass' ? 'bg-green-800 text-green-200' : ($r->verdict === 'incomplete' ? 'bg-yellow-800 text-yellow-200' : 'bg-red-800 text-red-200') }}">{{ $r->verdict }}</span></td><td class="py-1 {{ $r->runbook_reviewed ? 'text-green-400' : 'text-red-400' }}">{{ $r->runbook_reviewed ? '✓' : '✗' }}</td><td class="py-1 {{ $r->escalation_validated ? 'text-green-400' : 'text-red-400' }}">{{ $r->escalation_validated ? '✓' : '✗' }}</td><td class="py-1 {{ $r->shift_handoff_ready ? 'text-green-400' : 'text-red-400' }}">{{ $r->shift_handoff_ready ? '✓' : '✗' }}</td><td class="py-1 {{ $r->incident_workflow_tested ? 'text-green-400' : 'text-red-400' }}">{{ $r->incident_workflow_tested ? '✓' : '✗' }}</td><td class="py-1">{{ $r->acknowledgment_latency_seconds ?? '—' }}</td><td class="py-1">{{ $r->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
