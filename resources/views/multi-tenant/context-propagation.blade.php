<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Tenant Context Propagation Explorer</h2><p class="text-xs text-amber-400/80 mt-1">Tenant governance workflows are replay-safe and isolation-enforced. No hidden tenant crossover, unrestricted export, or autonomous mutation is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Tenant governance workflows are replay-safe and isolation-enforced. No hidden tenant crossover, unrestricted export, or autonomous mutation is executed.</div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Context Propagation Runs</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Run ID</th><th class="text-left py-1">Tenant</th><th class="text-left py-1">Trace ID</th><th class="text-left py-1">Context OK</th><th class="text-left py-1">Hops</th><th class="text-left py-1">Attr Failures</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($runs as $r)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($r->run_id, 20) }}</td><td class="py-1">{{ $r->tenant_id }}</td><td class="py-1 font-mono text-xs">{{ Str::limit($r->trace_id, 16) }}</td><td class="py-1"><span class="px-1 rounded {{ $r->context_ok ? 'bg-green-800 text-green-200' : 'bg-red-800 text-red-200' }}">{{ $r->context_ok ? 'yes' : 'no' }}</span></td><td class="py-1">{{ $r->hops_validated }}/{{ $r->hops_total }}</td><td class="py-1 {{ $r->attribution_failures > 0 ? 'text-red-400' : 'text-slate-400' }}">{{ $r->attribution_failures }}</td><td class="py-1">{{ $r->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
