<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Technical Readiness Reports
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-blue-50 border border-blue-200 rounded p-3 text-sm text-blue-800">
            Domain-level technical readiness reports. All gates must pass per domain before cutover approval.
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Report ID</th>
                        <th class="px-4 py-2 text-left">Domain</th>
                        <th class="px-4 py-2 text-left">Score</th>
                        <th class="px-4 py-2 text-left">Soak</th>
                        <th class="px-4 py-2 text-left">Replay</th>
                        <th class="px-4 py-2 text-left">Contract</th>
                        <th class="px-4 py-2 text-left">Registry</th>
                        <th class="px-4 py-2 text-left">Fleet Sim</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($reports as $report)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($report->tech_report_id, 0, 20) }}…</td>
                            <td class="px-4 py-2">{{ $report->report_domain }}</td>
                            <td class="px-4 py-2">{{ number_format($report->domain_readiness_score * 100, 1) }}%</td>
                            <td class="px-4 py-2 {{ $report->soak_passed ? 'text-green-600' : 'text-red-500' }}">{{ $report->soak_passed ? 'PASS' : 'FAIL' }}</td>
                            <td class="px-4 py-2 {{ $report->replay_integrity_verified ? 'text-green-600' : 'text-red-500' }}">{{ $report->replay_integrity_verified ? 'PASS' : 'FAIL' }}</td>
                            <td class="px-4 py-2 {{ $report->contract_compliant ? 'text-green-600' : 'text-red-500' }}">{{ $report->contract_compliant ? 'PASS' : 'FAIL' }}</td>
                            <td class="px-4 py-2 {{ $report->rule_registry_valid ? 'text-green-600' : 'text-red-500' }}">{{ $report->rule_registry_valid ? 'PASS' : 'FAIL' }}</td>
                            <td class="px-4 py-2 {{ $report->fleet_simulation_passed ? 'text-green-600' : 'text-red-500' }}">{{ $report->fleet_simulation_passed ? 'PASS' : 'FAIL' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $report->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-4 text-center text-gray-400 text-sm">No technical reports generated.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $reports->links() }}</div>

    </div>
</x-app-layout>
