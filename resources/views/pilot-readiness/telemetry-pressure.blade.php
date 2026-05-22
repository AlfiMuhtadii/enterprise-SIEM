<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Telemetry Onboarding Pressure Viewer</h2><p class="text-xs text-amber-400/80 mt-1">Pilot governance workflows are bounded, replay-safe, and approval-gated. No autonomous deployment, destructive rollback, or unrestricted telemetry onboarding is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Pilot governance workflows are bounded, replay-safe, and approval-gated. No autonomous deployment, destructive rollback, or unrestricted telemetry onboarding is executed.</div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            @foreach(['normal','elevated','high','critical'] as $level)
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $level === 'critical' ? 'text-red-300' : ($level === 'high' ? 'text-orange-300' : ($level === 'elevated' ? 'text-yellow-300' : 'text-green-300')) }}">{{ $byLevel[$level] ?? 0 }}</div>
                <div class="text-xs text-slate-400 mt-1 capitalize">{{ $level }}</div>
            </div>
            @endforeach
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Pressure Snapshots</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Snapshot</th><th class="text-left py-1">Tenant</th><th class="text-left py-1">EPS</th><th class="text-left py-1">Queue Growth</th><th class="text-left py-1">Storage MB/h</th><th class="text-left py-1">Endpoints</th><th class="text-left py-1">Replay Amp</th><th class="text-left py-1">Level</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($snapshots as $s)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($s->snapshot_id, 16) }}</td><td class="py-1">{{ $s->tenant_id }}</td><td class="py-1 {{ $s->events_per_second >= 5000 ? 'text-red-400' : 'text-slate-300' }}">{{ number_format($s->events_per_second) }}</td><td class="py-1">{{ number_format($s->queue_growth_rate) }}</td><td class="py-1">{{ number_format($s->storage_growth_mb_per_hour, 1) }}</td><td class="py-1">{{ $s->endpoint_count }}</td><td class="py-1 {{ $s->replay_amplification_factor > 3 ? 'text-orange-400' : 'text-slate-300' }}">{{ number_format($s->replay_amplification_factor, 2) }}×</td><td class="py-1"><span class="px-1 rounded {{ $s->pressure_level === 'critical' ? 'bg-red-800 text-red-200' : ($s->pressure_level === 'high' ? 'bg-orange-800 text-orange-200' : ($s->pressure_level === 'elevated' ? 'bg-yellow-800 text-yellow-200' : 'bg-green-800 text-green-200')) }}">{{ $s->pressure_level }}</span></td><td class="py-1">{{ $s->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
