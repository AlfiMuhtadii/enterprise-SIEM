<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Collector Restart Audit</h2><p class="text-xs text-amber-400/80 mt-1">Sensor governance workflows are advisory-only and replay-safe. No autonomous remediation, kernel enforcement, or destructive endpoint action is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Sensor governance workflows are advisory-only and replay-safe. No autonomous remediation, kernel enforcement, or destructive endpoint action is executed.</div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Audit ID</th><th class="text-left py-1">Agent</th><th class="text-left py-1">Reason</th><th class="text-left py-1">Count 24h</th><th class="text-left py-1">Operator</th><th class="text-left py-1">Crash</th><th class="text-left py-1">Prior State</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($audits as $a)<tr class="border-b border-slate-800"><td class="py-1 font-mono">{{ $a->audit_id }}</td><td class="py-1">{{ $a->agent_id }}</td><td class="py-1">{{ $a->restart_reason ?? '—' }}</td><td class="py-1">{{ $a->restart_count_24h }}</td><td class="py-1">{{ $a->operator_initiated ? 'Yes' : 'No' }}</td><td class="py-1 {{ $a->crash_induced ? 'text-red-300' : '' }}">{{ $a->crash_induced ? 'Yes' : 'No' }}</td><td class="py-1">{{ $a->prior_health_state ?? '—' }}</td><td class="py-1">{{ $a->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
