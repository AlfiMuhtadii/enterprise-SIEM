<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Infrastructure Cost Explorer</h2></x-slot>
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
                        <th class="px-4 py-2 text-left">Window</th>
                        <th class="px-4 py-2 text-left">Storage</th>
                        <th class="px-4 py-2 text-left">Ingestion</th>
                        <th class="px-4 py-2 text-left">Replay Amp</th>
                        <th class="px-4 py-2 text-left">HA Overhead</th>
                        <th class="px-4 py-2 text-left">Total (est.)</th>
                        <th class="px-4 py-2 text-left">Efficiency</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reports as $report)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $report->cost_report_id }}</td>
                        <td class="px-4 py-2">{{ $report->cluster_id }}</td>
                        <td class="px-4 py-2">{{ $report->cost_window }}</td>
                        <td class="px-4 py-2">${{ number_format($report->storage_cost_estimate, 2) }}</td>
                        <td class="px-4 py-2">${{ number_format($report->ingestion_cost_estimate, 2) }}</td>
                        <td class="px-4 py-2">${{ number_format($report->replay_amplification_cost, 2) }}</td>
                        <td class="px-4 py-2">${{ number_format($report->ha_overhead_cost, 2) }}</td>
                        <td class="px-4 py-2 font-semibold">${{ number_format($report->total_cost_estimate, 2) }}</td>
                        <td class="px-4 py-2">{{ number_format($report->utilization_efficiency_pct, 1) }}%</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $reports->links() }}</div>
    </div>
</x-app-layout>
