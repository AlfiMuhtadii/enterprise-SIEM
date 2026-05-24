<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Operational Validation Dashboard
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> Release stabilization workflows are bounded, replay-safe, and audit-visible.
            No destructive deployment automation, uncontrolled feature expansion, or autonomous remediation is executed.
        </div>

        @if($degradedCount > 0)
        <div class="bg-red-50 border border-red-300 rounded p-3 text-sm text-red-800">
            <strong>Degradation Detected:</strong> {{ $degradedCount }} domain(s) have detected degradation beyond drift threshold.
        </div>
        @endif

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Baseline ID</th>
                        <th class="px-4 py-2 text-left">Domain</th>
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Score</th>
                        <th class="px-4 py-2 text-left">Previous</th>
                        <th class="px-4 py-2 text-left">Drift</th>
                        <th class="px-4 py-2 text-left">Within Bounds</th>
                        <th class="px-4 py-2 text-left">Degradation</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($baselines as $baseline)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($baseline->baseline_id, 0, 16) }}…</td>
                            <td class="px-4 py-2">{{ $baseline->validation_domain }}</td>
                            <td class="px-4 py-2">
                                <span class="px-2 py-0.5 rounded text-xs {{ $baseline->validation_state === 'passing' ? 'bg-green-100 text-green-700' : ($baseline->validation_state === 'failing' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700') }}">
                                    {{ $baseline->validation_state }}
                                </span>
                            </td>
                            <td class="px-4 py-2">{{ number_format($baseline->baseline_score * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ number_format($baseline->previous_score * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ number_format($baseline->drift_delta * 100, 2) }}%</td>
                            <td class="px-4 py-2 {{ $baseline->within_bounds ? 'text-green-600' : 'text-red-500' }}">{{ $baseline->within_bounds ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 {{ $baseline->degradation_detected ? 'text-red-600 font-medium' : 'text-gray-400' }}">{{ $baseline->degradation_detected ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $baseline->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-4 text-center text-gray-400 text-sm">No operational baselines recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $baselines->links() }}</div>

    </div>
</x-app-layout>
