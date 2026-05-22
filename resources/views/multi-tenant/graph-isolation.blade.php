<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Tenant Graph Isolation Explorer</h2><p class="text-xs text-amber-400/80 mt-1">Tenant governance workflows are replay-safe and isolation-enforced. No hidden tenant crossover, unrestricted export, or autonomous mutation is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Tenant governance workflows are replay-safe and isolation-enforced. No hidden tenant crossover, unrestricted export, or autonomous mutation is executed.</div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Graph Isolation Reports</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Report ID</th><th class="text-left py-1">Tenant</th><th class="text-left py-1">Graph</th><th class="text-left py-1">Verdict</th><th class="text-left py-1">Nodes</th><th class="text-left py-1">Edges</th><th class="text-left py-1">X-Tenant Edges</th><th class="text-left py-1">Depth</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($reports as $r)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($r->report_id, 20) }}</td><td class="py-1">{{ $r->tenant_id }}</td><td class="py-1 font-mono text-xs">{{ Str::limit($r->graph_id, 16) }}</td><td class="py-1"><span class="px-1 rounded {{ $r->isolation_ok ? 'bg-green-800 text-green-200' : 'bg-red-800 text-red-200' }}">{{ $r->verdict }}</span></td><td class="py-1">{{ $r->nodes_validated }}</td><td class="py-1">{{ $r->edges_validated }}</td><td class="py-1 {{ $r->cross_tenant_edges_detected > 0 ? 'text-red-400' : 'text-slate-400' }}">{{ $r->cross_tenant_edges_detected }}</td><td class="py-1">{{ $r->traversal_depth }}</td><td class="py-1">{{ $r->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
