<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Production Acceptance Gates
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-blue-50 border border-blue-200 rounded p-3 text-sm text-blue-800">
            Gate pass rate: <strong>{{ number_format($gatePassRate * 100, 1) }}%</strong>. Self-approve blocked on all gates.
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Gate Run ID</th>
                        <th class="px-4 py-2 text-left">Gate Name</th>
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Score</th>
                        <th class="px-4 py-2 text-left">Passed</th>
                        <th class="px-4 py-2 text-left">Self-Approve Blocked</th>
                        <th class="px-4 py-2 text-left">Certification ID</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($gates as $gate)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($gate->gate_run_id, 0, 20) }}…</td>
                            <td class="px-4 py-2">{{ $gate->gate_name }}</td>
                            <td class="px-4 py-2">
                                <span class="px-2 py-0.5 rounded text-xs {{ $gate->gate_state === 'passed' ? 'bg-green-100 text-green-700' : ($gate->gate_state === 'failed' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600') }}">
                                    {{ $gate->gate_state }}
                                </span>
                            </td>
                            <td class="px-4 py-2">{{ number_format($gate->gate_score * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ $gate->gate_passed ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 text-green-600 font-medium">{{ $gate->self_approve_blocked ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $gate->certification_id ? substr($gate->certification_id, 0, 16) . '…' : '—' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $gate->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-4 text-center text-gray-400 text-sm">No gate evaluations found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $gates->links() }}</div>

    </div>
</x-app-layout>
