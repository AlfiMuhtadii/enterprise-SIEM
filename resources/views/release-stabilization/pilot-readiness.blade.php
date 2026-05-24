<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Pilot Deployment Readiness Console
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> Release stabilization workflows are bounded, replay-safe, and audit-visible.
            No destructive deployment automation, uncontrolled feature expansion, or autonomous remediation is executed.
            Max pilot endpoints: <strong>{{ $maxEndpoints }}</strong>.
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Run ID</th>
                        <th class="px-4 py-2 text-left">Scope</th>
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Endpoints</th>
                        <th class="px-4 py-2 text-left">Score</th>
                        <th class="px-4 py-2 text-left">Onboarding</th>
                        <th class="px-4 py-2 text-left">HA</th>
                        <th class="px-4 py-2 text-left">Rollback</th>
                        <th class="px-4 py-2 text-left">Telemetry</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($runs as $run)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($run->pilot_prep_run_id, 0, 18) }}…</td>
                            <td class="px-4 py-2">{{ $run->preparation_scope }}</td>
                            <td class="px-4 py-2">
                                <span class="px-2 py-0.5 rounded text-xs {{ $run->preparation_state === 'passed' ? 'bg-green-100 text-green-700' : ($run->preparation_state === 'failed' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600') }}">
                                    {{ $run->preparation_state }}
                                </span>
                            </td>
                            <td class="px-4 py-2">{{ $run->pilot_endpoint_count }}</td>
                            <td class="px-4 py-2">{{ number_format($run->readiness_score * 100, 1) }}%</td>
                            <td class="px-4 py-2 {{ $run->onboarding_ready ? 'text-green-600' : 'text-red-500' }}">{{ $run->onboarding_ready ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 {{ $run->ha_ready ? 'text-green-600' : 'text-red-500' }}">{{ $run->ha_ready ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 {{ $run->rollback_ready ? 'text-green-600' : 'text-red-500' }}">{{ $run->rollback_ready ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 {{ $run->telemetry_continuous ? 'text-green-600' : 'text-red-500' }}">{{ $run->telemetry_continuous ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $run->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-4 py-4 text-center text-gray-400 text-sm">No pilot preparation runs.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $runs->links() }}</div>

    </div>
</x-app-layout>
