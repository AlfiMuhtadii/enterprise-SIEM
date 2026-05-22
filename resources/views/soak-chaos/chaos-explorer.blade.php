<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Chaos Simulation Explorer</h2><p class="text-xs text-amber-400/80 mt-1">Operational soak and chaos workflows are bounded, replay-safe, and advisory-only. No destructive infrastructure mutation or autonomous remediation is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Operational soak and chaos workflows are bounded, replay-safe, and advisory-only. No destructive infrastructure mutation or autonomous remediation is executed.</div>
        <div class="grid grid-cols-3 gap-4">
            @foreach(['pass','fail','partial'] as $v)
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $v === 'fail' ? 'text-red-300' : ($v === 'partial' ? 'text-yellow-300' : 'text-green-300') }}">{{ $byVerdict[$v] ?? 0 }}</div>
                <div class="text-xs text-slate-400 mt-1 capitalize">{{ $v }}</div>
            </div>
            @endforeach
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Chaos Simulation Runs</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">ID</th><th class="text-left py-1">Scenario</th><th class="text-left py-1">Duration</th><th class="text-left py-1">Verdict</th><th class="text-left py-1">Injected</th><th class="text-left py-1">Recovered</th><th class="text-left py-1">Replay Safe</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($runs as $r)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($r->simulation_id, 20) }}</td><td class="py-1">{{ $r->scenario }}</td><td class="py-1">{{ $r->duration_seconds }}s</td><td class="py-1"><span class="px-1 rounded {{ $r->verdict === 'pass' ? 'bg-green-800 text-green-200' : ($r->verdict === 'partial' ? 'bg-yellow-800 text-yellow-200' : 'bg-red-800 text-red-200') }}">{{ $r->verdict }}</span></td><td class="py-1">{{ $r->failures_injected }}</td><td class="py-1">{{ $r->recoveries_observed }}</td><td class="py-1 {{ $r->replay_safe ? 'text-green-400' : 'text-red-400' }}">{{ $r->replay_safe ? 'yes' : 'no' }}</td><td class="py-1">{{ $r->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
