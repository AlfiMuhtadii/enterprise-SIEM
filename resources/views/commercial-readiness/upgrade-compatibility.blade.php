<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Upgrade Compatibility Explorer</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-blue-50 border border-blue-300 rounded p-3 mb-6 text-sm text-blue-800">
            Commercial readiness workflows are bounded, replay-safe, and audit-visible. No destructive support action, hidden telemetry collection, or autonomous remediation is executed.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">Report ID</th>
                        <th class="px-4 py-2 text-left">Release ID</th>
                        <th class="px-4 py-2 text-left">From</th>
                        <th class="px-4 py-2 text-left">To</th>
                        <th class="px-4 py-2 text-left">Schema Safe</th>
                        <th class="px-4 py-2 text-left">Migration Safe</th>
                        <th class="px-4 py-2 text-left">Rollback Safe</th>
                        <th class="px-4 py-2 text-left">Advisory</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reports as $report)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $report->compatibility_report_id }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ $report->release_id }}</td>
                        <td class="px-4 py-2 font-mono">{{ $report->from_version }}</td>
                        <td class="px-4 py-2 font-mono">{{ $report->to_version }}</td>
                        <td class="px-4 py-2 text-{{ $report->schema_compatible ? 'green' : 'red' }}-700">{{ $report->schema_compatible ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2 text-{{ $report->migration_safe ? 'green' : 'red' }}-700">{{ $report->migration_safe ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2 text-{{ $report->rollback_safe ? 'green' : 'red' }}-700">{{ $report->rollback_safe ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2">{{ $report->upgrade_advisory_issued ? 'Issued' : '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $reports->links() }}</div>
    </div>
</x-app-layout>
