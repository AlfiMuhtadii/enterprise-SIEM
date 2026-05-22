<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Tenant Onboarding Console</h2><p class="text-xs text-amber-400/80 mt-1">Pilot governance workflows are bounded, replay-safe, and approval-gated. No autonomous deployment, destructive rollback, or unrestricted telemetry onboarding is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Pilot governance workflows are bounded, replay-safe, and approval-gated. No autonomous deployment, destructive rollback, or unrestricted telemetry onboarding is executed.</div>
        @if($approvals->count() > 0)
        <div class="rounded border border-yellow-700/30 bg-yellow-900/10 p-4">
            <h3 class="text-sm font-semibold text-yellow-300 mb-2">Pending Approvals ({{ $approvals->count() }})</h3>
            @foreach($approvals as $a)
            <div class="text-xs text-slate-300 py-1 border-b border-slate-800">{{ $a->run_id }} — requested by {{ $a->requested_by }} — self-approve blocked: {{ $a->self_approve_blocked ? 'yes' : 'no' }}</div>
            @endforeach
        </div>
        @endif
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Onboarding Runs</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Run ID</th><th class="text-left py-1">Tenant</th><th class="text-left py-1">Status</th><th class="text-left py-1">Operator</th><th class="text-left py-1">EPS Limit</th><th class="text-left py-1">Endpoints</th><th class="text-left py-1">Op. Ack</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($runs as $r)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($r->run_id, 20) }}</td><td class="py-1">{{ $r->tenant_id }}</td><td class="py-1 capitalize">{{ $r->status }}</td><td class="py-1">{{ $r->operator_id ?? '—' }}</td><td class="py-1">{{ number_format($r->max_events_per_second) }}</td><td class="py-1">{{ $r->max_endpoints }}</td><td class="py-1 {{ $r->operator_acknowledged ? 'text-green-400' : 'text-yellow-400' }}">{{ $r->operator_acknowledged ? 'yes' : 'no' }}</td><td class="py-1">{{ $r->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
