<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Failover Coordination Timeline</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-indigo-50 border border-indigo-300 rounded p-3 mb-6 text-sm text-indigo-800">
            Enterprise-scale governance workflows are bounded, replay-safe, and advisory-only. No uncontrolled failover, destructive infrastructure orchestration, or autonomous remediation is executed.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">Coordination ID</th>
                        <th class="px-4 py-2 text-left">Cluster</th>
                        <th class="px-4 py-2 text-left">Type</th>
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Duration</th>
                        <th class="px-4 py-2 text-left">Dep Ordered</th>
                        <th class="px-4 py-2 text-left">Replay Verified</th>
                        <th class="px-4 py-2 text-left">Rollback Ready</th>
                        <th class="px-4 py-2 text-left">Uncontrolled</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($history as $record)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $record->failover_coordination_id }}</td>
                        <td class="px-4 py-2">{{ $record->cluster_id }}</td>
                        <td class="px-4 py-2">{{ $record->failover_type }}</td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 rounded text-xs {{ $record->failover_state === 'completed' ? 'bg-green-100 text-green-800' : ($record->failover_state === 'aborted' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800') }}">
                                {{ $record->failover_state }}
                            </span>
                        </td>
                        <td class="px-4 py-2">{{ number_format($record->duration_s, 1) }}s</td>
                        <td class="px-4 py-2">{{ $record->dependency_ordered ? '✓' : '✗' }}</td>
                        <td class="px-4 py-2">{{ $record->replay_verified ? '✓' : '✗' }}</td>
                        <td class="px-4 py-2">{{ $record->rollback_ready ? '✓' : '✗' }}</td>
                        <td class="px-4 py-2 text-{{ $record->uncontrolled_failover ? 'red' : 'green' }}-700">{{ $record->uncontrolled_failover ? 'Yes' : 'No' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $history->links() }}</div>
    </div>
</x-app-layout>
