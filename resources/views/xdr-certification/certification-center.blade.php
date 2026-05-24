<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            XDR Certification Center
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> All certification workflows are advisory. No autonomous approval. Self-approve blocked.
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Certification ID</th>
                        <th class="px-4 py-2 text-left">Type</th>
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Readiness</th>
                        <th class="px-4 py-2 text-left">Gate Pass Rate</th>
                        <th class="px-4 py-2 text-left">Soak</th>
                        <th class="px-4 py-2 text-left">Replay</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($certifications as $cert)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($cert->certification_id, 0, 20) }}…</td>
                            <td class="px-4 py-2">{{ $cert->certification_type }}</td>
                            <td class="px-4 py-2">
                                <span class="px-2 py-0.5 rounded text-xs font-medium {{ $cert->certification_state === 'passed' ? 'bg-green-100 text-green-700' : ($cert->certification_state === 'failed' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600') }}">
                                    {{ $cert->certification_state }}
                                </span>
                            </td>
                            <td class="px-4 py-2">{{ number_format($cert->readiness_score * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ number_format($cert->gate_pass_rate * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ $cert->soak_validated ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2">{{ $cert->replay_verified ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $cert->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-4 text-center text-gray-400 text-sm">No certifications found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $certifications->links() }}</div>

    </div>
</x-app-layout>
