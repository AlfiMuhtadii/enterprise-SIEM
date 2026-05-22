<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Tenant Evidence Integrity Dashboard</h2><p class="text-xs text-amber-400/80 mt-1">Tenant governance workflows are replay-safe and isolation-enforced. No hidden tenant crossover, unrestricted export, or autonomous mutation is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Tenant governance workflows are replay-safe and isolation-enforced. No hidden tenant crossover, unrestricted export, or autonomous mutation is executed.</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $stats['total'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Total Reports</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-green-300">{{ $stats['pass'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Pass</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['fail'] > 0 ? 'text-red-300' : 'text-slate-400' }}">{{ $stats['fail'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Fail</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['partial'] > 0 ? 'text-yellow-300' : 'text-slate-400' }}">{{ $stats['partial'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Partial</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['cross_tenant'] > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $stats['cross_tenant'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Cross-Tenant Refs</div>
            </div>
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Evidence Integrity Reports</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Report ID</th><th class="text-left py-1">Tenant</th><th class="text-left py-1">Checked</th><th class="text-left py-1">OK</th><th class="text-left py-1">Failed</th><th class="text-left py-1">X-Tenant</th><th class="text-left py-1">Verdict</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($reports as $r)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($r->report_id, 20) }}</td><td class="py-1">{{ $r->tenant_id }}</td><td class="py-1">{{ $r->evidence_refs_checked }}</td><td class="py-1 text-green-400">{{ $r->integrity_ok }}</td><td class="py-1 {{ $r->integrity_failed > 0 ? 'text-red-400' : 'text-slate-400' }}">{{ $r->integrity_failed }}</td><td class="py-1 {{ $r->cross_tenant_refs > 0 ? 'text-red-400' : 'text-slate-400' }}">{{ $r->cross_tenant_refs }}</td><td class="py-1"><span class="px-1 rounded {{ $r->verdict === 'pass' ? 'bg-green-800 text-green-200' : ($r->verdict === 'partial' ? 'bg-yellow-800 text-yellow-200' : 'bg-red-800 text-red-200') }}">{{ $r->verdict }}</span></td><td class="py-1">{{ $r->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
