<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Telemetry Distribution Dashboard</h2></x-slot>
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
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Ingestion (EPS)</th>
                        <th class="px-4 py-2 text-left">Queue Pressure</th>
                        <th class="px-4 py-2 text-left">Cross-Cluster Lag</th>
                        <th class="px-4 py-2 text-left">Amplification</th>
                        <th class="px-4 py-2 text-left">Balanced</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reports as $report)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $report->distribution_report_id }}</td>
                        <td class="px-4 py-2">{{ $report->cluster_id }}</td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 rounded text-xs {{ $report->distribution_state === 'balanced' ? 'bg-green-100 text-green-800' : ($report->distribution_state === 'overloaded' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800') }}">
                                {{ $report->distribution_state }}
                            </span>
                        </td>
                        <td class="px-4 py-2">{{ number_format($report->ingestion_rate_eps, 0) }}</td>
                        <td class="px-4 py-2">{{ number_format($report->queue_pressure_pct, 1) }}%</td>
                        <td class="px-4 py-2">{{ number_format($report->cross_cluster_lag_ms, 2) }}ms</td>
                        <td class="px-4 py-2">{{ number_format($report->replay_amplification_ratio, 2) }}x</td>
                        <td class="px-4 py-2 text-{{ $report->load_balanced ? 'green' : 'yellow' }}-700">{{ $report->load_balanced ? 'Yes' : 'No' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $reports->links() }}</div>
    </div>
</x-app-layout>
