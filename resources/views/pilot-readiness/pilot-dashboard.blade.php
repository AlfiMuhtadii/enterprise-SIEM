<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Pilot Readiness Dashboard</h2><p class="text-xs text-amber-400/80 mt-1">Pilot governance workflows are bounded, replay-safe, and approval-gated. No autonomous deployment, destructive rollback, or unrestricted telemetry onboarding is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Pilot governance workflows are bounded, replay-safe, and approval-gated. No autonomous deployment, destructive rollback, or unrestricted telemetry onboarding is executed.</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $stats['total_pilots'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Total Pilots</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['active_pilots'] > 0 ? 'text-green-300' : 'text-slate-400' }}">{{ $stats['active_pilots'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Active Pilots</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['pending_approvals'] > 0 ? 'text-yellow-300' : 'text-slate-400' }}">{{ $stats['pending_approvals'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Pending Approvals</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['critical_pressure'] > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $stats['critical_pressure'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Critical Pressure</div>
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['health_failures'] > 0 ? 'text-red-300' : 'text-green-300' }}">{{ $stats['health_failures'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Health Failures</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['rollback_fail'] > 0 ? 'text-orange-300' : 'text-slate-400' }}">{{ $stats['rollback_fail'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Rollback Failures</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-green-300">{{ $stats['metrics_targets_met'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Metrics Targets Met</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['operator_not_ready'] > 0 ? 'text-yellow-300' : 'text-green-300' }}">{{ $stats['operator_ready'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Operators Ready</div>
            </div>
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Recent Pilot Runs</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Run ID</th><th class="text-left py-1">Tenant</th><th class="text-left py-1">Status</th><th class="text-left py-1">Max EPS</th><th class="text-left py-1">Max Endpoints</th><th class="text-left py-1">Duration</th><th class="text-left py-1">Checklist</th><th class="text-left py-1">Rollback Drill</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($recent as $r)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($r->run_id, 20) }}</td><td class="py-1">{{ $r->tenant_id }}</td><td class="py-1"><span class="px-1 rounded {{ $r->status === 'active' ? 'bg-green-800 text-green-200' : ($r->status === 'aborted' ? 'bg-red-800 text-red-200' : 'bg-slate-700 text-slate-300') }}">{{ $r->status }}</span></td><td class="py-1">{{ number_format($r->max_events_per_second) }}</td><td class="py-1">{{ $r->max_endpoints }}</td><td class="py-1">{{ $r->pilot_duration_hours }}h</td><td class="py-1 {{ $r->readiness_checklist_complete ? 'text-green-400' : 'text-yellow-400' }}">{{ $r->readiness_checklist_complete ? '✓' : '✗' }}</td><td class="py-1 {{ $r->rollback_drill_complete ? 'text-green-400' : 'text-yellow-400' }}">{{ $r->rollback_drill_complete ? '✓' : '✗' }}</td><td class="py-1">{{ $r->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
