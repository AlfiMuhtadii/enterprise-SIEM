<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Production Pilot Health Dashboard</h2></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Pilot execution workflows are bounded, replay-safe, and advisory-only. No autonomous remediation, destructive rollback, or unrestricted onboarding is executed.
        </div>
        <div class="overflow-x-auto rounded border border-slate-700">
            <table class="w-full text-sm text-slate-300">
                <thead class="bg-slate-800 text-slate-400 text-xs uppercase">
                    <tr><th class="px-4 py-2">Checkpoint ID</th><th>Type</th><th>Telemetry Cont.</th><th>Replay Success</th><th>FP Ratio</th><th>RB Score</th><th>Health OK</th></tr>
                </thead>
                <tbody>
                @foreach($checkpoints as $c)
                <tr class="border-t border-slate-700">
                    <td class="px-4 py-2 font-mono text-xs">{{ $c->checkpoint_id }}</td>
                    <td>{{ $c->checkpoint_type }}</td>
                    <td>{{ number_format($c->telemetry_continuity_pct * 100, 1) }}%</td>
                    <td>{{ number_format($c->replay_recovery_success_pct * 100, 1) }}%</td>
                    <td>{{ number_format($c->false_positive_ratio * 100, 2) }}%</td>
                    <td>{{ number_format($c->rollback_readiness_score * 100, 1) }}%</td>
                    <td class="{{ $c->health_ok ? 'text-green-400' : 'text-red-400' }}">{{ $c->health_ok ? '✓' : '✗' }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $checkpoints->links() }}
    </div>
</x-app-layout>
