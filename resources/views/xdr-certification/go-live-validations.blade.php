<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Go-Live Validation Runs
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> No autonomous go-live execution. Rollback-ready required for all passed validations.
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Run ID</th>
                        <th class="px-4 py-2 text-left">Scope</th>
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Score</th>
                        <th class="px-4 py-2 text-left">Soak Met</th>
                        <th class="px-4 py-2 text-left">Gate Met</th>
                        <th class="px-4 py-2 text-left">Risk OK</th>
                        <th class="px-4 py-2 text-left">Rollback Ready</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($runs as $run)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($run->go_live_run_id, 0, 20) }}…</td>
                            <td class="px-4 py-2">{{ $run->validation_scope }}</td>
                            <td class="px-4 py-2">
                                <span class="px-2 py-0.5 rounded text-xs {{ $run->validation_state === 'passed' ? 'bg-green-100 text-green-700' : ($run->validation_state === 'failed' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600') }}">
                                    {{ $run->validation_state }}
                                </span>
                            </td>
                            <td class="px-4 py-2">{{ number_format($run->validation_score * 100, 1) }}%</td>
                            <td class="px-4 py-2 {{ $run->soak_requirement_met ? 'text-green-600' : 'text-red-500' }}">{{ $run->soak_requirement_met ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 {{ $run->gate_requirement_met ? 'text-green-600' : 'text-red-500' }}">{{ $run->gate_requirement_met ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 {{ $run->risk_acceptable ? 'text-green-600' : 'text-red-500' }}">{{ $run->risk_acceptable ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 {{ $run->rollback_ready ? 'text-green-600' : 'text-red-500' }}">{{ $run->rollback_ready ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $run->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-4 text-center text-gray-400 text-sm">No go-live validations run yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $runs->links() }}</div>

    </div>
</x-app-layout>
