<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Pilot Audit Timeline</h2></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Pilot execution workflows are bounded, replay-safe, and advisory-only. No autonomous remediation, destructive rollback, or unrestricted onboarding is executed.
        </div>
        <div class="overflow-x-auto rounded border border-slate-700">
            <table class="w-full text-sm text-slate-300">
                <thead class="bg-slate-800 text-slate-400 text-xs uppercase">
                    <tr><th class="px-4 py-2">Audit ID</th><th>Event Type</th><th>Actor</th><th>Outcome</th><th>Description</th><th>Date</th></tr>
                </thead>
                <tbody>
                @foreach($auditEvents as $a)
                <tr class="border-t border-slate-700">
                    <td class="px-4 py-2 font-mono text-xs">{{ $a->audit_id }}</td>
                    <td>{{ $a->event_type }}</td>
                    <td>{{ $a->actor }}</td>
                    <td class="{{ $a->outcome === 'success' ? 'text-green-400' : ($a->outcome === 'failure' ? 'text-red-400' : 'text-yellow-400') }}">{{ $a->outcome }}</td>
                    <td class="text-xs">{{ $a->description }}</td>
                    <td class="text-xs text-slate-400">{{ $a->created_at->format('Y-m-d H:i') }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $auditEvents->links() }}
    </div>
</x-app-layout>
