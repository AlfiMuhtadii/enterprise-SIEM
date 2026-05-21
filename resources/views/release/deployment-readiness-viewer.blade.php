<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Deployment Readiness Viewer</h2>
        <p class="text-xs text-amber-400/80 mt-1">Release governance workflows are approval-gated and replay-safe. No autonomous deployment, destructive rollback, or hidden environment mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> Release governance workflows are approval-gated and replay-safe. No autonomous deployment, destructive rollback, or hidden environment mutation is executed.
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <div class="overflow-x-auto">
            <table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700">
                    <th class="text-left py-1">Run ID</th><th class="text-left py-1">Release</th><th class="text-left py-1">Verdict</th><th class="text-left py-1">Checks Passed</th><th class="text-left py-1">Triggered By</th><th class="text-left py-1">At</th>
                </tr></thead>
                <tbody>
                @foreach($runs as $r)
                <tr class="border-b border-slate-800">
                    <td class="py-1 font-mono">{{ $r->run_id }}</td>
                    <td class="py-1">{{ $r->release_id ?? '—' }}</td>
                    <td class="py-1"><span class="px-1.5 py-0.5 rounded text-xs {{ $r->overall_verdict === 'go' ? 'bg-green-800 text-green-200' : 'bg-red-800 text-red-200' }}">{{ $r->overall_verdict }}</span></td>
                    <td class="py-1">{{ $r->validation_details['pass_count'] ?? 0 }}/{{ $r->validation_details['total_checks'] ?? 0 }}</td>
                    <td class="py-1">{{ $r->triggered_by }}</td>
                    <td class="py-1">{{ $r->created_at }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
    </div>
</x-app-layout>
