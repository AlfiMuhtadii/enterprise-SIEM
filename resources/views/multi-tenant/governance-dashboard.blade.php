<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Multi-Tenant Governance Dashboard</h2><p class="text-xs text-amber-400/80 mt-1">Tenant governance workflows are replay-safe and isolation-enforced. No hidden tenant crossover, unrestricted export, or autonomous mutation is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Tenant governance workflows are replay-safe and isolation-enforced. No hidden tenant crossover, unrestricted export, or autonomous mutation is executed.</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['isolation_failures'] > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $stats['isolation_failures'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Isolation Failures</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['boundary_violations'] > 0 ? 'text-orange-300' : 'text-slate-400' }}">{{ $stats['boundary_violations'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Boundary Violations</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['namespace_crossovers'] > 0 ? 'text-yellow-300' : 'text-green-300' }}">{{ $stats['namespace_crossovers'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Namespace Crossovers</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['evidence_integrity_failures'] > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $stats['evidence_integrity_failures'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Evidence Failures</div>
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['replay_contaminations'] > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $stats['replay_contaminations'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Replay Contaminations</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['graph_isolation_failures'] > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $stats['graph_isolation_failures'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Graph Failures</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['export_failures'] > 0 ? 'text-yellow-300' : 'text-slate-400' }}">{{ $stats['export_failures'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Export Failures</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['context_propagation_failures'] > 0 ? 'text-yellow-300' : 'text-green-300' }}">{{ $stats['context_propagation_failures'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Context Failures</div>
            </div>
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Recent Replay Lineage</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Lineage ID</th><th class="text-left py-1">Tenant</th><th class="text-left py-1">Replay</th><th class="text-left py-1">Origin</th><th class="text-left py-1">Depth</th><th class="text-left py-1">Clean</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($lineage as $l)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($l->lineage_id, 20) }}</td><td class="py-1">{{ $l->tenant_id }}</td><td class="py-1 font-mono text-xs">{{ Str::limit($l->replay_id, 16) }}</td><td class="py-1">{{ $l->origin_tenant_id }}</td><td class="py-1">{{ $l->replay_depth }}</td><td class="py-1"><span class="px-1 rounded {{ $l->lineage_clean ? 'bg-green-800 text-green-200' : 'bg-red-800 text-red-200' }}">{{ $l->lineage_clean ? 'yes' : 'no' }}</span></td><td class="py-1">{{ $l->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
