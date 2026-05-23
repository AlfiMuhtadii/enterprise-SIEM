<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Distributed Replay Continuity Viewer</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-indigo-50 border border-indigo-300 rounded p-3 mb-6 text-sm text-indigo-800">
            Enterprise-scale governance workflows are bounded, replay-safe, and advisory-only. No uncontrolled failover, destructive infrastructure orchestration, or autonomous remediation is executed.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">Continuity ID</th>
                        <th class="px-4 py-2 text-left">Cluster</th>
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Coverage</th>
                        <th class="px-4 py-2 text-left">Gaps</th>
                        <th class="px-4 py-2 text-left">Amplification</th>
                        <th class="px-4 py-2 text-left">Cross-Cluster</th>
                        <th class="px-4 py-2 text-left">Durability</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($records as $record)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $record->replay_continuity_id }}</td>
                        <td class="px-4 py-2">{{ $record->cluster_id }}</td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 rounded text-xs {{ $record->continuity_state === 'continuous' ? 'bg-green-100 text-green-800' : ($record->continuity_state === 'gap_detected' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800') }}">
                                {{ $record->continuity_state }}
                            </span>
                        </td>
                        <td class="px-4 py-2">{{ number_format($record->replay_coverage_pct, 1) }}%</td>
                        <td class="px-4 py-2 text-{{ $record->gap_count > 0 ? 'red' : 'green' }}-700">{{ $record->gap_count }}</td>
                        <td class="px-4 py-2">{{ number_format($record->amplification_ratio, 2) }}x</td>
                        <td class="px-4 py-2">{{ $record->cross_cluster_continuous ? '✓' : '✗' }}</td>
                        <td class="px-4 py-2">{{ $record->durability_verified ? '✓' : '✗' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $records->links() }}</div>
    </div>
</x-app-layout>
