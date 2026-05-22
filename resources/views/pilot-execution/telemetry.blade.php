<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Telemetry Validation Viewer</h2></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Pilot execution workflows are bounded, replay-safe, and advisory-only. No autonomous remediation, destructive rollback, or unrestricted onboarding is executed.
        </div>
        <div class="overflow-x-auto rounded border border-slate-700">
            <table class="w-full text-sm text-slate-300">
                <thead class="bg-slate-800 text-slate-400 text-xs uppercase">
                    <tr><th class="px-4 py-2">Validation ID</th><th>Run ID</th><th>EPS</th><th>Continuity</th><th>Queue Lag</th><th>Dup Rate</th><th>Passed</th></tr>
                </thead>
                <tbody>
                @foreach($validations as $v)
                <tr class="border-t border-slate-700">
                    <td class="px-4 py-2 font-mono text-xs">{{ $v->validation_id }}</td>
                    <td class="font-mono text-xs">{{ $v->run_id }}</td>
                    <td>{{ number_format($v->events_per_second, 1) }}</td>
                    <td>{{ number_format($v->telemetry_continuity_pct * 100, 1) }}%</td>
                    <td>{{ number_format($v->queue_lag) }}</td>
                    <td>{{ number_format($v->duplicate_event_rate * 100, 2) }}%</td>
                    <td class="{{ $v->validation_passed ? 'text-green-400' : 'text-red-400' }}">{{ $v->validation_passed ? '✓' : '✗' }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $validations->links() }}
    </div>
</x-app-layout>
