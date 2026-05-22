<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Tenant Boundary Violation Timeline</h2><p class="text-xs text-amber-400/80 mt-1">Tenant governance workflows are replay-safe and isolation-enforced. No hidden tenant crossover, unrestricted export, or autonomous mutation is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Tenant governance workflows are replay-safe and isolation-enforced. No hidden tenant crossover, unrestricted export, or autonomous mutation is executed.</div>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
            @foreach(['graph_crossover','replay_contamination','export_leakage','context_override','namespace_crossover'] as $type)
            <div class="rounded border border-slate-700 bg-slate-800/50 p-3 text-center">
                <div class="text-xl font-bold {{ ($byType[$type] ?? 0) > 0 ? 'text-red-300' : 'text-slate-400' }}">{{ $byType[$type] ?? 0 }}</div>
                <div class="text-xs text-slate-400 mt-1">{{ str_replace('_', ' ', $type) }}</div>
            </div>
            @endforeach
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Violation Timeline</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Report ID</th><th class="text-left py-1">Tenant</th><th class="text-left py-1">Type</th><th class="text-left py-1">Severity</th><th class="text-left py-1">Description</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($timeline as $v)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($v->report_id, 20) }}</td><td class="py-1">{{ $v->tenant_id }}</td><td class="py-1">{{ $v->violation_type }}</td><td class="py-1"><span class="px-1 rounded {{ $v->severity === 'critical' ? 'bg-red-800 text-red-200' : ($v->severity === 'high' ? 'bg-orange-800 text-orange-200' : 'bg-yellow-800 text-yellow-200') }}">{{ $v->severity }}</span></td><td class="py-1 max-w-xs truncate">{{ $v->description }}</td><td class="py-1">{{ $v->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
