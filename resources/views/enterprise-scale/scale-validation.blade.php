<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Scale Profile Validation Console</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-indigo-50 border border-indigo-300 rounded p-3 mb-6 text-sm text-indigo-800">
            Enterprise-scale governance workflows are bounded, replay-safe, and advisory-only. No uncontrolled failover, destructive infrastructure orchestration, or autonomous remediation is executed.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">Run ID</th>
                        <th class="px-4 py-2 text-left">Profile</th>
                        <th class="px-4 py-2 text-left">Endpoints</th>
                        <th class="px-4 py-2 text-left">Telemetry</th>
                        <th class="px-4 py-2 text-left">Replay Dur.</th>
                        <th class="px-4 py-2 text-left">Analyst</th>
                        <th class="px-4 py-2 text-left">Queue Rec.</th>
                        <th class="px-4 py-2 text-left">Op Score</th>
                        <th class="px-4 py-2 text-left">Result</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($runs as $run)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $run->scale_run_id }}</td>
                        <td class="px-4 py-2">{{ $run->scale_profile }}</td>
                        <td class="px-4 py-2">{{ $run->endpoint_count }}</td>
                        <td class="px-4 py-2">{{ number_format($run->telemetry_continuity_pct, 1) }}%</td>
                        <td class="px-4 py-2">{{ number_format($run->replay_durability_pct, 1) }}%</td>
                        <td class="px-4 py-2">{{ number_format($run->analyst_workload_score * 100, 1) }}%</td>
                        <td class="px-4 py-2">{{ number_format($run->queue_recovery_score * 100, 1) }}%</td>
                        <td class="px-4 py-2">{{ number_format($run->operational_score * 100, 1) }}%</td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 rounded text-xs {{ $run->scale_passed ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                {{ $run->scale_passed ? 'PASS' : 'FAIL' }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $runs->links() }}</div>
    </div>
</x-app-layout>
