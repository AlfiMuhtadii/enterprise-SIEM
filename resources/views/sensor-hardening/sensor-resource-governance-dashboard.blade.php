<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Sensor Resource Governance Dashboard</h2><p class="text-xs text-amber-400/80 mt-1">Sensor governance workflows are advisory-only and replay-safe. No autonomous remediation, kernel enforcement, or destructive endpoint action is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Sensor governance workflows are advisory-only and replay-safe. No autonomous remediation, kernel enforcement, or destructive endpoint action is executed.</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach(['normal','elevated','high','critical'] as $state)
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $state === 'critical' ? 'text-red-300' : ($state === 'high' ? 'text-orange-300' : ($state === 'elevated' ? 'text-yellow-300' : 'text-green-300')) }}">{{ $byState->get($state)?->count() ?? 0 }}</div>
                <div class="text-xs text-slate-400 mt-1 capitalize">{{ $state }}</div>
            </div>
            @endforeach
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Sequence Continuity Validations</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Validation ID</th><th class="text-left py-1">Agent</th><th class="text-left py-1">Verdict</th><th class="text-left py-1">Expected</th><th class="text-left py-1">Observed</th><th class="text-left py-1">Gaps</th><th class="text-left py-1">Dups</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($sequenceRuns as $r)<tr class="border-b border-slate-800"><td class="py-1 font-mono">{{ $r->validation_id }}</td><td class="py-1">{{ $r->agent_id }}</td><td class="py-1"><span class="px-1 rounded text-xs {{ $r->continuity_ok ? 'bg-green-800 text-green-200' : 'bg-red-800 text-red-200' }}">{{ $r->verdict }}</span></td><td class="py-1">{{ $r->expected_sequence }}</td><td class="py-1">{{ $r->observed_sequence }}</td><td class="py-1">{{ $r->gap_count }}</td><td class="py-1">{{ $r->duplicate_count }}</td><td class="py-1">{{ $r->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
