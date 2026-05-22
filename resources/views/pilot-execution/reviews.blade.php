<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Operational Review Timeline</h2></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Pilot execution workflows are bounded, replay-safe, and advisory-only. No autonomous remediation, destructive rollback, or unrestricted onboarding is executed.
        </div>
        <div class="overflow-x-auto rounded border border-slate-700">
            <table class="w-full text-sm text-slate-300">
                <thead class="bg-slate-800 text-slate-400 text-xs uppercase">
                    <tr><th class="px-4 py-2">Review ID</th><th>Type</th><th>Reviewed By</th><th>Verdict</th><th>Follow-up</th><th>Date</th></tr>
                </thead>
                <tbody>
                @foreach($reviews as $r)
                <tr class="border-t border-slate-700">
                    <td class="px-4 py-2 font-mono text-xs">{{ $r->review_id }}</td>
                    <td>{{ $r->review_type }}</td>
                    <td>{{ $r->reviewed_by }}</td>
                    <td>{{ $r->verdict }}</td>
                    <td>{{ $r->requires_followup ? 'Yes' : 'No' }}</td>
                    <td class="text-xs text-slate-400">{{ $r->created_at->format('Y-m-d H:i') }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $reviews->links() }}
    </div>
</x-app-layout>
