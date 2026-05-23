<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Deployment Readiness Console</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-blue-50 border border-blue-300 rounded p-3 mb-6 text-sm text-blue-800">
            Commercial readiness workflows are bounded, replay-safe, and audit-visible. No destructive support action, hidden telemetry collection, or autonomous remediation is executed.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">Report ID</th>
                        <th class="px-4 py-2 text-left">Tenant</th>
                        <th class="px-4 py-2 text-left">Environment</th>
                        <th class="px-4 py-2 text-left">Score</th>
                        <th class="px-4 py-2 text-left">Onboarding</th>
                        <th class="px-4 py-2 text-left">Telemetry</th>
                        <th class="px-4 py-2 text-left">Env Valid</th>
                        <th class="px-4 py-2 text-left">Ready</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reports as $report)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $report->readiness_report_id }}</td>
                        <td class="px-4 py-2">{{ $report->tenant_id }}</td>
                        <td class="px-4 py-2">{{ $report->environment }}</td>
                        <td class="px-4 py-2">{{ number_format($report->readiness_score * 100, 1) }}%</td>
                        <td class="px-4 py-2">{{ $report->onboarding_complete ? '✓' : '✗' }}</td>
                        <td class="px-4 py-2">{{ $report->telemetry_healthy ? '✓' : '✗' }}</td>
                        <td class="px-4 py-2">{{ $report->environment_valid ? '✓' : '✗' }}</td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 rounded text-xs {{ $report->deployment_ready ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                {{ $report->deployment_ready ? 'Ready' : 'Not Ready' }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $reports->links() }}</div>
    </div>
</x-app-layout>
