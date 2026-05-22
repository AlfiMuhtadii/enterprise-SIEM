<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Observation Window Explorer</h2></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Pilot execution workflows are bounded, replay-safe, and advisory-only. No autonomous remediation, destructive rollback, or unrestricted onboarding is executed.
        </div>
        <div class="overflow-x-auto rounded border border-slate-700">
            <table class="w-full text-sm text-slate-300">
                <thead class="bg-slate-800 text-slate-400 text-xs uppercase">
                    <tr><th class="px-4 py-2">Checkpoint ID</th><th>Window</th><th>Telemetry Cont.</th><th>Replay Success</th><th>Drift Stability</th><th>RB Readiness</th><th>Criteria Met</th></tr>
                </thead>
                <tbody>
                @foreach($checkpoints as $c)
                <tr class="border-t border-slate-700">
                    <td class="px-4 py-2 font-mono text-xs">{{ $c->checkpoint_id }}</td>
                    <td>{{ $c->window_type }}</td>
                    <td>{{ number_format($c->telemetry_continuity_pct * 100, 1) }}%</td>
                    <td>{{ number_format($c->replay_recovery_success_pct * 100, 1) }}%</td>
                    <td>{{ number_format($c->drift_stability_pct * 100, 1) }}%</td>
                    <td>{{ number_format($c->rollback_readiness_score * 100, 1) }}%</td>
                    <td class="{{ $c->criteria_met ? 'text-green-400' : 'text-red-400' }}">{{ $c->criteria_met ? '✓' : '✗' }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $checkpoints->links() }}
    </div>
</x-app-layout>
