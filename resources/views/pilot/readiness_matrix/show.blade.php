<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Pilot Readiness Matrix — {{ Str::limit($run->matrix_run_id, 28) }}
        </h2>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4">
            <p class="text-sm text-yellow-800 font-medium">Advisory Only — No autonomous promotion. This matrix is informational only.</p>
        </div>

        {{-- Summary card --}}
        <div class="bg-white shadow rounded-lg p-6 grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <p class="text-xs text-gray-500 uppercase">Status</p>
                @php
                    $color = match($run->status) {
                        'passed'    => 'text-green-700',
                        'failed'    => 'text-red-700',
                        default     => 'text-yellow-700',
                    };
                @endphp
                <p class="text-2xl font-bold {{ $color }}">{{ strtoupper($run->status) }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 uppercase">Matrix Score</p>
                <p class="text-2xl font-bold text-gray-800">{{ number_format($run->matrix_score * 100, 1) }}%</p>
                <p class="text-xs text-gray-400">threshold: 80%</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 uppercase">Gates (P/W/F)</p>
                <p class="text-lg font-semibold">
                    <span class="text-green-600">{{ $run->gates_passed }}</span> /
                    <span class="text-yellow-600">{{ $run->gates_warned }}</span> /
                    <span class="text-red-600">{{ $run->gates_failed }}</span>
                </p>
                <p class="text-xs text-gray-400">total: {{ $run->total_gates }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 uppercase">Required Gates</p>
                @if($run->required_gates_pass)
                    <p class="text-lg font-bold text-green-600">ALL PASS</p>
                @else
                    <p class="text-lg font-bold text-red-600">BLOCKED</p>
                @endif
            </div>
        </div>

        {{-- Gate evaluations by dimension --}}
        @foreach($report['by_dimension'] as $dimension => $gates)
        @if(count($gates) > 0)
        <div class="bg-white shadow rounded-lg p-6">
            <h3 class="text-md font-semibold text-gray-700 mb-3 capitalize">{{ $dimension }} Dimension</h3>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium text-gray-600">Gate</th>
                        <th class="px-4 py-2 text-left font-medium text-gray-600">Status</th>
                        <th class="px-4 py-2 text-left font-medium text-gray-600">Score</th>
                        <th class="px-4 py-2 text-left font-medium text-gray-600">Detail</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($gates as $gate)
                    <tr>
                        <td class="px-4 py-2 font-mono text-xs text-gray-700">{{ $gate['gate_id'] }}</td>
                        <td class="px-4 py-2">
                            @php
                                $gc = match($gate['status']) {
                                    'pass' => 'bg-green-100 text-green-800',
                                    'warn' => 'bg-yellow-100 text-yellow-800',
                                    'fail' => 'bg-red-100 text-red-800',
                                    default => 'bg-gray-100 text-gray-500',
                                };
                            @endphp
                            <span class="px-2 py-1 rounded text-xs font-semibold {{ $gc }}">{{ strtoupper($gate['status']) }}</span>
                            @if(in_array($gate['gate_id'], $report['required_gates']))
                                <span class="ml-1 text-xs text-purple-600 font-semibold">required</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-600">{{ $gate['score'] !== null ? number_format($gate['score'] * 100, 1).'%' : '—' }}</td>
                        <td class="px-4 py-2 text-gray-500 text-xs">{{ $gate['detail'] ?? '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
        @endforeach

        <div class="flex gap-4 text-sm">
            <a href="{{ route('soc.pilot.readiness_matrix.index') }}" class="text-gray-500 hover:underline">&larr; Back to matrix list</a>
            <a href="{{ route('soc.pilot.readiness_matrix.report', $run->matrix_run_id) }}" class="text-indigo-600 hover:underline" target="_blank">Download JSON report</a>
        </div>
    </div>
</x-app-layout>
