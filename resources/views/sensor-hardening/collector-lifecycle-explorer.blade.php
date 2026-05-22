<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Collector Lifecycle Explorer</h2><p class="text-xs text-amber-400/80 mt-1">Sensor governance workflows are advisory-only and replay-safe. No autonomous remediation, kernel enforcement, or destructive endpoint action is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Sensor governance workflows are advisory-only and replay-safe. No autonomous remediation, kernel enforcement, or destructive endpoint action is executed.</div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Event ID</th><th class="text-left py-1">Agent</th><th class="text-left py-1">State</th><th class="text-left py-1">Previous</th><th class="text-left py-1">Type</th><th class="text-left py-1">Reason</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($events as $e)<tr class="border-b border-slate-800"><td class="py-1 font-mono">{{ $e->event_id }}</td><td class="py-1">{{ $e->agent_id }}</td><td class="py-1">{{ $e->health_state }}</td><td class="py-1">{{ $e->previous_state ?? '—' }}</td><td class="py-1">{{ $e->event_type }}</td><td class="py-1">{{ $e->reason ?? '—' }}</td><td class="py-1">{{ $e->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
