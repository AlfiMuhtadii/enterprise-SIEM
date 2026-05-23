<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Customer Operations Health Viewer</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-blue-50 border border-blue-300 rounded p-3 mb-6 text-sm text-blue-800">
            Commercial readiness workflows are bounded, replay-safe, and audit-visible. No destructive support action, hidden telemetry collection, or autonomous remediation is executed.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">Tenant</th>
                        <th class="px-4 py-2 text-left">Health Score</th>
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Deployment</th>
                        <th class="px-4 py-2 text-left">Telemetry</th>
                        <th class="px-4 py-2 text-left">Support</th>
                        <th class="px-4 py-2 text-left">Onboarded</th>
                        <th class="px-4 py-2 text-left">Updated</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($records as $record)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $record->tenant_id }}</td>
                        <td class="px-4 py-2">{{ number_format($record->health_score * 100, 1) }}%</td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 rounded text-xs
                                {{ $record->health_state === 'healthy' ? 'bg-green-100 text-green-800' :
                                   ($record->health_state === 'degraded' ? 'bg-yellow-100 text-yellow-800' :
                                   ($record->health_state === 'at_risk' ? 'bg-orange-100 text-orange-800' : 'bg-red-100 text-red-800')) }}">
                                {{ $record->health_state }}
                            </span>
                        </td>
                        <td class="px-4 py-2">{{ $record->deployment_healthy ? '✓' : '✗' }}</td>
                        <td class="px-4 py-2">{{ $record->telemetry_continuous ? '✓' : '✗' }}</td>
                        <td class="px-4 py-2">{{ $record->support_ready ? '✓' : '✗' }}</td>
                        <td class="px-4 py-2">{{ $record->onboarding_complete ? '✓' : '✗' }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $record->updated_at->diffForHumans() }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $records->links() }}</div>
    </div>
</x-app-layout>
