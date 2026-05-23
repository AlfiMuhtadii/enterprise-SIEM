<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">HA Governance Viewer</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-indigo-50 border border-indigo-300 rounded p-3 mb-6 text-sm text-indigo-800">
            Enterprise-scale governance workflows are bounded, replay-safe, and advisory-only. No uncontrolled failover, destructive infrastructure orchestration, or autonomous remediation is executed.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">Run ID</th>
                        <th class="px-4 py-2 text-left">Cluster</th>
                        <th class="px-4 py-2 text-left">Check Type</th>
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Score</th>
                        <th class="px-4 py-2 text-left">Quorum</th>
                        <th class="px-4 py-2 text-left">Replica</th>
                        <th class="px-4 py-2 text-left">Replay</th>
                        <th class="px-4 py-2 text-left">Failover Ready</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($runs as $run)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $run->ha_run_id }}</td>
                        <td class="px-4 py-2">{{ $run->cluster_id }}</td>
                        <td class="px-4 py-2">{{ $run->ha_check_type }}</td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 rounded text-xs {{ $run->ha_state === 'passing' ? 'bg-green-100 text-green-800' : ($run->ha_state === 'degraded' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') }}">
                                {{ $run->ha_state }}
                            </span>
                        </td>
                        <td class="px-4 py-2">{{ number_format($run->ha_score * 100, 1) }}%</td>
                        <td class="px-4 py-2">{{ $run->quorum_valid ? '✓' : '✗' }}</td>
                        <td class="px-4 py-2">{{ $run->replica_continuous ? '✓' : '✗' }}</td>
                        <td class="px-4 py-2">{{ $run->replay_continuous ? '✓' : '✗' }}</td>
                        <td class="px-4 py-2">{{ $run->failover_ready ? '✓' : '✗' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $runs->links() }}</div>
    </div>
</x-app-layout>
