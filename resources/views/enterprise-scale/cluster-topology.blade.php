<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Cluster Topology Explorer</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-indigo-50 border border-indigo-300 rounded p-3 mb-6 text-sm text-indigo-800">
            Enterprise-scale governance workflows are bounded, replay-safe, and advisory-only. No uncontrolled failover, destructive infrastructure orchestration, or autonomous remediation is executed.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">Report ID</th>
                        <th class="px-4 py-2 text-left">Cluster</th>
                        <th class="px-4 py-2 text-left">Role</th>
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Nodes</th>
                        <th class="px-4 py-2 text-left">Replicas</th>
                        <th class="px-4 py-2 text-left">Replication Lag</th>
                        <th class="px-4 py-2 text-left">Quorum</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reports as $report)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $report->topology_report_id }}</td>
                        <td class="px-4 py-2">{{ $report->cluster_id }}</td>
                        <td class="px-4 py-2">{{ $report->cluster_role }}</td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 rounded text-xs {{ $report->topology_state === 'healthy' ? 'bg-green-100 text-green-800' : ($report->topology_state === 'degraded' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') }}">
                                {{ $report->topology_state }}
                            </span>
                        </td>
                        <td class="px-4 py-2">{{ $report->node_count }}</td>
                        <td class="px-4 py-2">{{ $report->replica_count }}</td>
                        <td class="px-4 py-2">{{ number_format($report->replication_lag_ms, 2) }}ms</td>
                        <td class="px-4 py-2 text-{{ $report->quorum_achieved ? 'green' : 'red' }}-700">{{ $report->quorum_achieved ? 'Yes' : 'No' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $reports->links() }}</div>
    </div>
</x-app-layout>
