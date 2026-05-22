<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Drift Review Dashboard</h2></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Pilot execution workflows are bounded, replay-safe, and advisory-only. No autonomous remediation, destructive rollback, or unrestricted onboarding is executed.
        </div>
        <div class="overflow-x-auto rounded border border-slate-700">
            <table class="w-full text-sm text-slate-300">
                <thead class="bg-slate-800 text-slate-400 text-xs uppercase">
                    <tr><th class="px-4 py-2">Drift Review ID</th><th>Type</th><th>Severity</th><th>Magnitude</th><th>Verdict</th><th>Rollback Triggered</th></tr>
                </thead>
                <tbody>
                @foreach($driftReviews as $d)
                <tr class="border-t border-slate-700">
                    <td class="px-4 py-2 font-mono text-xs">{{ $d->drift_review_id }}</td>
                    <td>{{ $d->drift_type }}</td>
                    <td>{{ $d->drift_severity }}</td>
                    <td>{{ number_format($d->drift_magnitude, 3) }}</td>
                    <td>{{ $d->verdict }}</td>
                    <td class="{{ $d->rollback_triggered ? 'text-yellow-400' : 'text-slate-400' }}">{{ $d->rollback_triggered ? 'Yes' : 'No' }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $driftReviews->links() }}
    </div>
</x-app-layout>
