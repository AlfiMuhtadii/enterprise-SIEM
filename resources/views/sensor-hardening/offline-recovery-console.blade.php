<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Offline Recovery Console</h2><p class="text-xs text-amber-400/80 mt-1">Sensor governance workflows are advisory-only and replay-safe. No autonomous remediation, kernel enforcement, or destructive endpoint action is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Sensor governance workflows are advisory-only and replay-safe. No autonomous remediation, kernel enforcement, or destructive endpoint action is executed.</div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Run ID</th><th class="text-left py-1">Agent</th><th class="text-left py-1">Verdict</th><th class="text-left py-1">Offline (s)</th><th class="text-left py-1">Buffered</th><th class="text-left py-1">Replayed</th><th class="text-left py-1">Dropped</th><th class="text-left py-1">Continuity</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($runs as $r)<tr class="border-b border-slate-800"><td class="py-1 font-mono">{{ $r->run_id }}</td><td class="py-1">{{ $r->agent_id }}</td><td class="py-1"><span class="px-1 rounded text-xs {{ $r->recovery_verdict === 'complete' ? 'bg-green-800 text-green-200' : ($r->recovery_verdict === 'partial' ? 'bg-yellow-800 text-yellow-200' : 'bg-red-800 text-red-200') }}">{{ $r->recovery_verdict }}</span></td><td class="py-1">{{ $r->offline_duration_seconds }}</td><td class="py-1">{{ $r->buffered_event_count }}</td><td class="py-1">{{ $r->replayed_event_count }}</td><td class="py-1">{{ $r->dropped_event_count }}</td><td class="py-1">{{ $r->sequence_continuity_ok ? 'OK' : 'Gap' }}</td><td class="py-1">{{ $r->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
