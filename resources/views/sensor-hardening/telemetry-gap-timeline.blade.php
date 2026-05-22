<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Telemetry Gap Timeline</h2><p class="text-xs text-amber-400/80 mt-1">Sensor governance workflows are advisory-only and replay-safe. No autonomous remediation, kernel enforcement, or destructive endpoint action is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Sensor governance workflows are advisory-only and replay-safe. No autonomous remediation, kernel enforcement, or destructive endpoint action is executed.</div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Report ID</th><th class="text-left py-1">Agent</th><th class="text-left py-1">Gap (s)</th><th class="text-left py-1">Est. Lost</th><th class="text-left py-1">Reason</th><th class="text-left py-1">Recovered</th><th class="text-left py-1">Replay</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($reports as $r)<tr class="border-b border-slate-800"><td class="py-1 font-mono">{{ $r->report_id }}</td><td class="py-1">{{ $r->agent_id }}</td><td class="py-1">{{ $r->gap_duration_seconds }}</td><td class="py-1">{{ $r->estimated_lost_events }}</td><td class="py-1">{{ $r->gap_reason ?? '—' }}</td><td class="py-1">{{ $r->recovered ? 'Yes' : 'No' }}</td><td class="py-1">{{ $r->replay_attempted ? 'Yes' : 'No' }}</td><td class="py-1">{{ $r->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
