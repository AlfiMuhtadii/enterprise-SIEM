<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Production Observation Window Explorer</h2><p class="text-xs text-amber-400/80 mt-1">Pilot governance workflows are bounded, replay-safe, and approval-gated. No autonomous deployment, destructive rollback, or unrestricted telemetry onboarding is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Pilot governance workflows are bounded, replay-safe, and approval-gated. No autonomous deployment, destructive rollback, or unrestricted telemetry onboarding is executed.</div>
        @if($active->count() > 0)
        <div class="rounded border border-green-700/30 bg-green-900/10 p-4">
            <h3 class="text-sm font-semibold text-green-300 mb-2">Active Windows ({{ $active->count() }})</h3>
            @foreach($active as $w)
            <div class="text-xs text-slate-300 py-1 border-b border-slate-800">{{ $w->window_id }} — {{ $w->tenant_id }} — phase: {{ $w->phase }} — health: {{ $w->health_ok ? 'OK' : 'DEGRADED' }}</div>
            @endforeach
        </div>
        @endif
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Observation Windows</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Window ID</th><th class="text-left py-1">Tenant</th><th class="text-left py-1">Phase</th><th class="text-left py-1">Status</th><th class="text-left py-1">Duration</th><th class="text-left py-1">Health OK</th><th class="text-left py-1">Targets Met</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($windows as $w)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($w->window_id, 20) }}</td><td class="py-1">{{ $w->tenant_id }}</td><td class="py-1">{{ $w->phase }}</td><td class="py-1"><span class="px-1 rounded {{ $w->status === 'active' ? 'bg-green-800 text-green-200' : ($w->status === 'aborted' ? 'bg-red-800 text-red-200' : 'bg-slate-700 text-slate-300') }}">{{ $w->status }}</span></td><td class="py-1">{{ $w->duration_hours }}h</td><td class="py-1 {{ $w->health_ok ? 'text-green-400' : 'text-red-400' }}">{{ $w->health_ok ? 'yes' : 'no' }}</td><td class="py-1 {{ $w->metrics_meeting_targets ? 'text-green-400' : 'text-yellow-400' }}">{{ $w->metrics_meeting_targets ? 'yes' : 'no' }}</td><td class="py-1">{{ $w->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
