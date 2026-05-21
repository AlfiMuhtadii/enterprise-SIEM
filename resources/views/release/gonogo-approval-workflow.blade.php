<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Go/No-Go Approval Workflow</h2>
        <p class="text-xs text-amber-400/80 mt-1">Release governance workflows are approval-gated and replay-safe. No autonomous deployment, destructive rollback, or hidden environment mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> Release governance workflows are approval-gated and replay-safe. No autonomous deployment, destructive rollback, or hidden environment mutation is executed.
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Approval Requests</h3>
            <div class="overflow-x-auto">
            <table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700">
                    <th class="text-left py-1">Request ID</th><th class="text-left py-1">Release</th><th class="text-left py-1">Status</th><th class="text-left py-1">Requested By</th><th class="text-left py-1">At</th>
                </tr></thead>
                <tbody>
                @foreach($requests as $r)
                <tr class="border-b border-slate-800">
                    <td class="py-1 font-mono">{{ $r->request_id }}</td>
                    <td class="py-1">{{ $r->release_id }}</td>
                    <td class="py-1">{{ $r->status }}</td>
                    <td class="py-1">{{ $r->requested_by }}</td>
                    <td class="py-1">{{ $r->created_at }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Go/No-Go Decisions</h3>
            <div class="overflow-x-auto">
            <table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700">
                    <th class="text-left py-1">Decision ID</th><th class="text-left py-1">Release</th><th class="text-left py-1">Decision</th><th class="text-left py-1">Decided By</th><th class="text-left py-1">At</th>
                </tr></thead>
                <tbody>
                @foreach($decisions as $d)
                <tr class="border-b border-slate-800">
                    <td class="py-1 font-mono">{{ $d->decision_id }}</td>
                    <td class="py-1">{{ $d->release_id }}</td>
                    <td class="py-1"><span class="px-1.5 py-0.5 rounded text-xs {{ $d->decision === 'go' ? 'bg-green-800 text-green-200' : 'bg-red-800 text-red-200' }}">{{ $d->decision }}</span></td>
                    <td class="py-1">{{ $d->decided_by }}</td>
                    <td class="py-1">{{ $d->created_at }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
    </div>
</x-app-layout>
