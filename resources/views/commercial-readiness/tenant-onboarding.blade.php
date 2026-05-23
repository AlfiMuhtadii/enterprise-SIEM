<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Tenant Onboarding Explorer</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-blue-50 border border-blue-300 rounded p-3 mb-6 text-sm text-blue-800">
            Commercial readiness workflows are bounded, replay-safe, and audit-visible. No destructive support action, hidden telemetry collection, or autonomous remediation is executed.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">Run ID</th>
                        <th class="px-4 py-2 text-left">Tenant</th>
                        <th class="px-4 py-2 text-left">Phase</th>
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Readiness</th>
                        <th class="px-4 py-2 text-left">Endpoints</th>
                        <th class="px-4 py-2 text-left">Rollback</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($runs as $run)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $run->onboarding_run_id }}</td>
                        <td class="px-4 py-2">{{ $run->tenant_id }}</td>
                        <td class="px-4 py-2">{{ $run->onboarding_phase }}</td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 rounded text-xs {{ $run->onboarding_state === 'completed' ? 'bg-green-100 text-green-800' : ($run->onboarding_state === 'failed' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800') }}">
                                {{ $run->onboarding_state }}
                            </span>
                        </td>
                        <td class="px-4 py-2">{{ number_format($run->readiness_score * 100, 1) }}%</td>
                        <td class="px-4 py-2">{{ $run->endpoint_count }}</td>
                        <td class="px-4 py-2">{{ $run->rollback_capable ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $run->created_at->diffForHumans() }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $runs->links() }}</div>
    </div>
</x-app-layout>
