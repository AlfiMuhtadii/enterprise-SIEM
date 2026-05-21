<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Operational Runbook Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1">Release governance workflows are approval-gated and replay-safe. No autonomous deployment, destructive rollback, or hidden environment mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> Release governance workflows are approval-gated and replay-safe. No autonomous deployment, destructive rollback, or hidden environment mutation is executed.
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700">
                    <th class="text-left py-1">Runbook ID</th><th class="text-left py-1">Title</th><th class="text-left py-1">Type</th><th class="text-left py-1">Version</th><th class="text-left py-1">Owner</th><th class="text-left py-1">Active</th>
                </tr></thead>
                <tbody>
                @foreach($runbooks as $rb)
                <tr class="border-b border-slate-800">
                    <td class="py-1 font-mono">{{ $rb->runbook_id }}</td>
                    <td class="py-1">{{ $rb->title }}</td>
                    <td class="py-1">{{ $rb->runbook_type }}</td>
                    <td class="py-1">{{ $rb->current_version }}</td>
                    <td class="py-1">{{ $rb->owner }}</td>
                    <td class="py-1">{{ $rb->is_active ? 'Yes' : 'No' }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
