<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Pilot Health Validation Explorer</h2><p class="text-xs text-amber-400/80 mt-1">Pilot governance workflows are bounded, replay-safe, and approval-gated. No autonomous deployment, destructive rollback, or unrestricted telemetry onboarding is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Pilot governance workflows are bounded, replay-safe, and approval-gated. No autonomous deployment, destructive rollback, or unrestricted telemetry onboarding is executed.</div>
        <div class="grid grid-cols-3 gap-4">
            @foreach(['pass','degraded','fail'] as $v)
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $v === 'fail' ? 'text-red-300' : ($v === 'degraded' ? 'text-yellow-300' : 'text-green-300') }}">{{ $stats[$v] }}</div>
                <div class="text-xs text-slate-400 mt-1 capitalize">{{ $v }}</div>
            </div>
            @endforeach
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Health Checks</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">ID</th><th class="text-left py-1">Tenant</th><th class="text-left py-1">Check</th><th class="text-left py-1">Verdict</th><th class="text-left py-1">Value</th><th class="text-left py-1">Threshold</th><th class="text-left py-1">Reason</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($checks as $c)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($c->validation_id, 16) }}</td><td class="py-1">{{ $c->tenant_id }}</td><td class="py-1">{{ $c->check_type }}</td><td class="py-1"><span class="px-1 rounded {{ $c->verdict === 'pass' ? 'bg-green-800 text-green-200' : ($c->verdict === 'degraded' ? 'bg-yellow-800 text-yellow-200' : 'bg-red-800 text-red-200') }}">{{ $c->verdict }}</span></td><td class="py-1">{{ $c->metric_value !== null ? number_format($c->metric_value, 3) : '—' }}</td><td class="py-1">{{ $c->threshold_value !== null ? number_format($c->threshold_value, 3) : '—' }}</td><td class="py-1 max-w-xs truncate text-slate-400">{{ $c->failure_reason ?? '—' }}</td><td class="py-1">{{ $c->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
