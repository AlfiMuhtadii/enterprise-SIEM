<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Release Governance Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Release governance workflows are approval-gated and replay-safe. No autonomous deployment, destructive rollback, or hidden environment mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> Release governance workflows are approval-gated and replay-safe. No autonomous deployment, destructive rollback, or hidden environment mutation is executed.
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('release.manifests') }}" class="px-3 py-1.5 text-xs rounded bg-blue-900/30 text-blue-300 border border-blue-700/40">Manifests</a>
            <a href="{{ route('release.readiness') }}" class="px-3 py-1.5 text-xs rounded bg-green-900/30 text-green-300 border border-green-700/40">Readiness</a>
            <a href="{{ route('release.drift') }}" class="px-3 py-1.5 text-xs rounded bg-yellow-900/30 text-yellow-300 border border-yellow-700/40">Drift</a>
            <a href="{{ route('release.rollback') }}" class="px-3 py-1.5 text-xs rounded bg-red-900/30 text-red-300 border border-red-700/40">Rollback</a>
            <a href="{{ route('release.gonogo') }}" class="px-3 py-1.5 text-xs rounded bg-purple-900/30 text-purple-300 border border-purple-700/40">Go/No-Go</a>
            <a href="{{ route('release.runbooks') }}" class="px-3 py-1.5 text-xs rounded bg-orange-900/30 text-orange-300 border border-orange-700/40">Runbooks</a>
            <a href="{{ route('release.audit') }}" class="px-3 py-1.5 text-xs rounded bg-cyan-900/30 text-cyan-300 border border-cyan-700/40">Audit</a>
            <a href="{{ route('release.safety') }}" class="px-3 py-1.5 text-xs rounded bg-gray-900/30 text-gray-300 border border-gray-700/40">Safety</a>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $stats['total_manifests'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Manifests</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-green-300">{{ $stats['go_verdicts'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Go Verdicts</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-yellow-300">{{ $stats['pending_approvals'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Pending Approvals</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['blocking_drift_reports'] > 0 ? 'text-red-300' : 'text-slate-400' }}">{{ $stats['blocking_drift_reports'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Blocking Drifts</div>
            </div>
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Recent Release Manifests</h3>
            <table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700">
                    <th class="text-left py-1">Release ID</th><th class="text-left py-1">Version</th><th class="text-left py-1">Status</th><th class="text-left py-1">Created By</th><th class="text-left py-1">At</th>
                </tr></thead>
                <tbody>
                @foreach($recentManifests as $m)
                <tr class="border-b border-slate-800">
                    <td class="py-1 font-mono">{{ $m->release_id }}</td>
                    <td class="py-1">{{ $m->release_version }}</td>
                    <td class="py-1">{{ $m->status }}</td>
                    <td class="py-1">{{ $m->created_by }}</td>
                    <td class="py-1">{{ $m->created_at }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
