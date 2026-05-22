<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Worker Restart Validation Explorer</h2><p class="text-xs text-amber-400/80 mt-1">Operational soak and chaos workflows are bounded, replay-safe, and advisory-only. No destructive infrastructure mutation or autonomous remediation is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Operational soak and chaos workflows are bounded, replay-safe, and advisory-only. No destructive infrastructure mutation or autonomous remediation is executed.</div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Worker Failure Events</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Event ID</th><th class="text-left py-1">Simulation</th><th class="text-left py-1">Failure Type</th><th class="text-left py-1">Outcome</th><th class="text-left py-1">Offset</th><th class="text-left py-1">Recovery (s)</th><th class="text-left py-1">Replay Safe</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($events as $e)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($e->event_id, 16) }}</td><td class="py-1 font-mono text-xs">{{ Str::limit($e->simulation_id, 16) }}</td><td class="py-1">{{ $e->failure_type }}</td><td class="py-1"><span class="px-1 rounded {{ $e->outcome === 'recovered' ? 'bg-green-800 text-green-200' : ($e->outcome === 'unrecovered' ? 'bg-red-800 text-red-200' : 'bg-slate-700 text-slate-300') }}">{{ $e->outcome }}</span></td><td class="py-1">{{ $e->offset_seconds }}s</td><td class="py-1">{{ $e->recovery_seconds ?? '-' }}</td><td class="py-1 {{ $e->replay_safe ? 'text-green-400' : 'text-red-400' }}">{{ $e->replay_safe ? 'yes' : 'no' }}</td><td class="py-1">{{ $e->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
