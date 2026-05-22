<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Recovery Validation Timeline</h2><p class="text-xs text-amber-400/80 mt-1">Operational soak and chaos workflows are bounded, replay-safe, and advisory-only. No destructive infrastructure mutation or autonomous remediation is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Operational soak and chaos workflows are bounded, replay-safe, and advisory-only. No destructive infrastructure mutation or autonomous remediation is executed.</div>
        <div class="grid grid-cols-3 gap-4">
            @foreach(['pass','fail','partial'] as $v)
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $v === 'fail' ? 'text-red-300' : ($v === 'partial' ? 'text-yellow-300' : 'text-green-300') }}">{{ $stats[$v] }}</div>
                <div class="text-xs text-slate-400 mt-1 capitalize">{{ $v }}</div>
            </div>
            @endforeach
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Recovery Artifacts</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Artifact ID</th><th class="text-left py-1">Type</th><th class="text-left py-1">Verdict</th><th class="text-left py-1">OK</th><th class="text-left py-1">Secs</th><th class="text-left py-1">Dups Prev.</th><th class="text-left py-1">Tenant OK</th><th class="text-left py-1">Graph OK</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($artifacts as $a)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($a->artifact_id, 16) }}</td><td class="py-1">{{ $a->recovery_type }}</td><td class="py-1"><span class="px-1 rounded {{ $a->verdict === 'pass' ? 'bg-green-800 text-green-200' : ($a->verdict === 'partial' ? 'bg-yellow-800 text-yellow-200' : 'bg-red-800 text-red-200') }}">{{ $a->verdict }}</span></td><td class="py-1 {{ $a->recovery_ok ? 'text-green-400' : 'text-red-400' }}">{{ $a->recovery_ok ? 'yes' : 'no' }}</td><td class="py-1">{{ $a->recovery_seconds }}</td><td class="py-1 {{ $a->duplicates_prevented ? 'text-green-400' : 'text-red-400' }}">{{ $a->duplicates_prevented ? 'yes' : 'no' }}</td><td class="py-1 {{ $a->tenant_isolation_preserved ? 'text-green-400' : 'text-red-400' }}">{{ $a->tenant_isolation_preserved ? 'yes' : 'no' }}</td><td class="py-1 {{ $a->graph_integrity_preserved ? 'text-green-400' : 'text-red-400' }}">{{ $a->graph_integrity_preserved ? 'yes' : 'no' }}</td><td class="py-1">{{ $a->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
