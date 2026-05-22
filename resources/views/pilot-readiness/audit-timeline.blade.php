<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Pilot Audit Timeline</h2><p class="text-xs text-amber-400/80 mt-1">Pilot governance workflows are bounded, replay-safe, and approval-gated. No autonomous deployment, destructive rollback, or unrestricted telemetry onboarding is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Pilot governance workflows are bounded, replay-safe, and approval-gated. No autonomous deployment, destructive rollback, or unrestricted telemetry onboarding is executed.</div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Audit Events</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Event ID</th><th class="text-left py-1">Tenant</th><th class="text-left py-1">Type</th><th class="text-left py-1">Actor</th><th class="text-left py-1">Description</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($events as $e)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($e->event_id, 16) }}</td><td class="py-1">{{ $e->tenant_id }}</td><td class="py-1"><span class="px-1 rounded bg-slate-700 text-slate-300 text-xs">{{ $e->event_type }}</span></td><td class="py-1">{{ $e->actor_id ?? '—' }}</td><td class="py-1 max-w-xs truncate">{{ $e->description }}</td><td class="py-1">{{ $e->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
