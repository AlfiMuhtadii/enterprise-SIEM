<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Replay Recovery Viewer</h2><p class="text-xs text-amber-400/80 mt-1">Operational soak and chaos workflows are bounded, replay-safe, and advisory-only. No destructive infrastructure mutation or autonomous remediation is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Operational soak and chaos workflows are bounded, replay-safe, and advisory-only. No destructive infrastructure mutation or autonomous remediation is executed.</div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Replay Recovery Runs</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Run ID</th><th class="text-left py-1">Trigger</th><th class="text-left py-1">Verdict</th><th class="text-left py-1">Pending</th><th class="text-left py-1">Replayed</th><th class="text-left py-1">Ordering</th><th class="text-left py-1">Dups Prev.</th><th class="text-left py-1">Tenant OK</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($runs as $r)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($r->run_id, 20) }}</td><td class="py-1">{{ $r->trigger }}</td><td class="py-1"><span class="px-1 rounded {{ $r->verdict === 'pass' ? 'bg-green-800 text-green-200' : ($r->verdict === 'partial' ? 'bg-yellow-800 text-yellow-200' : 'bg-red-800 text-red-200') }}">{{ $r->verdict }}</span></td><td class="py-1">{{ $r->events_pending }}</td><td class="py-1">{{ $r->events_replayed }}</td><td class="py-1 {{ $r->ordering_preserved ? 'text-green-400' : 'text-red-400' }}">{{ $r->ordering_preserved ? 'yes' : 'no' }}</td><td class="py-1 {{ $r->duplicates_prevented ? 'text-green-400' : 'text-red-400' }}">{{ $r->duplicates_prevented ? 'yes' : 'no' }}</td><td class="py-1 {{ $r->tenant_isolation_preserved ? 'text-green-400' : 'text-red-400' }}">{{ $r->tenant_isolation_preserved ? 'yes' : 'no' }}</td><td class="py-1">{{ $r->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
