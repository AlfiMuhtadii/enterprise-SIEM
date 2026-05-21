<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Environment Drift Timeline</h2>
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
                    <th class="text-left py-1">Report ID</th><th class="text-left py-1">Drift Type</th><th class="text-left py-1">Component</th><th class="text-left py-1">Severity</th><th class="text-left py-1">Blocking</th><th class="text-left py-1">Detected By</th><th class="text-left py-1">At</th>
                </tr></thead>
                <tbody>
                @foreach($reports as $r)
                <tr class="border-b border-slate-800">
                    <td class="py-1 font-mono">{{ $r->report_id }}</td>
                    <td class="py-1">{{ $r->drift_type }}</td>
                    <td class="py-1">{{ $r->component }}</td>
                    <td class="py-1">{{ $r->severity }}</td>
                    <td class="py-1">{{ $r->is_blocking ? 'Yes' : 'No' }}</td>
                    <td class="py-1">{{ $r->detected_by }}</td>
                    <td class="py-1">{{ $r->created_at }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
    </div>
</x-app-layout>
