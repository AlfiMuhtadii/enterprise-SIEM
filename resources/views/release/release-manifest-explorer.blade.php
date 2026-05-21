<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Release Manifest Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1">Release governance workflows are approval-gated and replay-safe. No autonomous deployment, destructive rollback, or hidden environment mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> Release governance workflows are approval-gated and replay-safe. No autonomous deployment, destructive rollback, or hidden environment mutation is executed.
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700">
                    <th class="text-left py-1">Release ID</th><th class="text-left py-1">Version</th><th class="text-left py-1">Status</th><th class="text-left py-1">Hash</th><th class="text-left py-1">Rollback Ref</th><th class="text-left py-1">Created By</th><th class="text-left py-1">At</th>
                </tr></thead>
                <tbody>
                @foreach($manifests as $m)
                <tr class="border-b border-slate-800">
                    <td class="py-1 font-mono">{{ $m->release_id }}</td>
                    <td class="py-1">{{ $m->release_version }}</td>
                    <td class="py-1">{{ $m->status }}</td>
                    <td class="py-1 font-mono">{{ substr($m->manifest_hash, 0, 12) }}…</td>
                    <td class="py-1">{{ $m->rollback_reference ?? '—' }}</td>
                    <td class="py-1">{{ $m->created_by }}</td>
                    <td class="py-1">{{ $m->created_at }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
