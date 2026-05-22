<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Rollback Readiness Console</h2></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Pilot execution workflows are bounded, replay-safe, and advisory-only. No autonomous remediation, destructive rollback, or unrestricted onboarding is executed.
        </div>
        <div class="overflow-x-auto rounded border border-slate-700">
            <table class="w-full text-sm text-slate-300">
                <thead class="bg-slate-800 text-slate-400 text-xs uppercase">
                    <tr><th class="px-4 py-2">Rollback ID</th><th>Trigger Reason</th><th>Triggered By</th><th>Status</th><th>Destructive</th><th>Isolation Preserved</th></tr>
                </thead>
                <tbody>
                @foreach($audits as $a)
                <tr class="border-t border-slate-700">
                    <td class="px-4 py-2 font-mono text-xs">{{ $a->rollback_id }}</td>
                    <td>{{ $a->trigger_reason }}</td>
                    <td>{{ $a->triggered_by }}</td>
                    <td>{{ $a->status }}</td>
                    <td class="{{ $a->destructive_action ? 'text-red-400' : 'text-green-400' }}">{{ $a->destructive_action ? 'Yes' : 'No' }}</td>
                    <td class="{{ $a->isolation_preserved ? 'text-green-400' : 'text-red-400' }}">{{ $a->isolation_preserved ? 'Yes' : 'No' }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $audits->links() }}
    </div>
</x-app-layout>
