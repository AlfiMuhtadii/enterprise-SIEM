<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Rollback Readiness Timeline</h2><p class="text-xs text-amber-400/80 mt-1">Pilot governance workflows are bounded, replay-safe, and approval-gated. No autonomous deployment, destructive rollback, or unrestricted telemetry onboarding is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Pilot governance workflows are bounded, replay-safe, and approval-gated. No autonomous deployment, destructive rollback, or unrestricted telemetry onboarding is executed.</div>
        <div class="grid grid-cols-3 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center"><div class="text-2xl font-bold text-green-300">{{ $stats['pass'] }}</div><div class="text-xs text-slate-400 mt-1">Pass</div></div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center"><div class="text-2xl font-bold {{ $stats['fail'] > 0 ? 'text-red-300' : 'text-slate-400' }}">{{ $stats['fail'] }}</div><div class="text-xs text-slate-400 mt-1">Fail</div></div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center"><div class="text-2xl font-bold {{ $stats['pending'] > 0 ? 'text-yellow-300' : 'text-slate-400' }}">{{ $stats['pending'] }}</div><div class="text-xs text-slate-400 mt-1">Pending Approval</div></div>
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Rollback Validations</h3>
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">ID</th><th class="text-left py-1">Tenant</th><th class="text-left py-1">Trigger</th><th class="text-left py-1">Verdict</th><th class="text-left py-1">Checkpoint</th><th class="text-left py-1">Approval</th><th class="text-left py-1">Safe</th><th class="text-left py-1">Approved By</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($validations as $v)<tr class="border-b border-slate-800"><td class="py-1 font-mono text-xs">{{ Str::limit($v->validation_id, 16) }}</td><td class="py-1">{{ $v->tenant_id }}</td><td class="py-1">{{ $v->trigger }}</td><td class="py-1"><span class="px-1 rounded {{ $v->verdict === 'pass' ? 'bg-green-800 text-green-200' : ($v->verdict === 'pending_approval' ? 'bg-yellow-800 text-yellow-200' : 'bg-red-800 text-red-200') }}">{{ $v->verdict }}</span></td><td class="py-1 {{ $v->checkpoint_valid ? 'text-green-400' : 'text-red-400' }}">{{ $v->checkpoint_valid ? 'yes' : 'no' }}</td><td class="py-1 {{ $v->approval_obtained ? 'text-green-400' : 'text-yellow-400' }}">{{ $v->approval_obtained ? 'yes' : 'no' }}</td><td class="py-1 {{ $v->rollback_safe ? 'text-green-400' : 'text-red-400' }}">{{ $v->rollback_safe ? 'yes' : 'no' }}</td><td class="py-1">{{ $v->approved_by ?? '—' }}</td><td class="py-1">{{ $v->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
