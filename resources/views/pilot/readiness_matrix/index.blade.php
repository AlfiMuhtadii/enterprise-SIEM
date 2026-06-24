<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Pilot Readiness Matrix — PILOT-034
        </h2>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6">
            <p class="text-sm text-yellow-800 font-medium">Advisory Only — No autonomous promotion. All matrix assessments require explicit analyst approval.</p>
        </div>

        <div class="bg-white shadow rounded-lg p-6">
            <h3 class="text-lg font-semibold text-gray-700 mb-4">Readiness Matrix Runs</h3>

            @if($runs->isEmpty())
                <p class="text-gray-500 text-sm">No matrix runs recorded yet.</p>
            @else
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium text-gray-600">Run ID</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-600">Tenant</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-600">Status</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-600">Score</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-600">Gates P/W/F</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-600">Required Gates</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-600">Finalized</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-600">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($runs as $run)
                        <tr>
                            <td class="px-4 py-2 font-mono text-xs text-gray-600">{{ Str::limit($run->matrix_run_id, 20) }}</td>
                            <td class="px-4 py-2 text-gray-700">{{ $run->tenant_id }}</td>
                            <td class="px-4 py-2">
                                @php
                                    $color = match($run->status) {
                                        'passed'    => 'bg-green-100 text-green-800',
                                        'failed'    => 'bg-red-100 text-red-800',
                                        'rejected'  => 'bg-gray-100 text-gray-600',
                                        'evaluating'=> 'bg-blue-100 text-blue-800',
                                        default     => 'bg-yellow-100 text-yellow-800',
                                    };
                                @endphp
                                <span class="px-2 py-1 rounded text-xs font-semibold {{ $color }}">{{ strtoupper($run->status) }}</span>
                            </td>
                            <td class="px-4 py-2 text-gray-700">{{ number_format($run->matrix_score * 100, 1) }}%</td>
                            <td class="px-4 py-2 text-gray-700">
                                <span class="text-green-600">{{ $run->gates_passed }}</span>/
                                <span class="text-yellow-600">{{ $run->gates_warned }}</span>/
                                <span class="text-red-600">{{ $run->gates_failed }}</span>
                            </td>
                            <td class="px-4 py-2">
                                @if($run->required_gates_pass)
                                    <span class="text-green-600 font-semibold">PASS</span>
                                @else
                                    <span class="text-red-600 font-semibold">FAIL</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-gray-500 text-xs">{{ $run->finalized_at ? $run->finalized_at->diffForHumans() : '—' }}</td>
                            <td class="px-4 py-2">
                                <a href="{{ route('soc.pilot.readiness_matrix.show', $run->matrix_run_id) }}" class="text-blue-600 hover:underline text-xs">View</a>
                                &nbsp;|&nbsp;
                                <a href="{{ route('soc.pilot.readiness_matrix.report', $run->matrix_run_id) }}" class="text-indigo-600 hover:underline text-xs" target="_blank">JSON</a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="mt-4">{{ $runs->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
