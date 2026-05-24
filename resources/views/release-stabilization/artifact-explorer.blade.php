<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Deployment Artifact Explorer
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> Release stabilization workflows are bounded, replay-safe, and audit-visible.
            No destructive deployment automation, uncontrolled feature expansion, or autonomous remediation is executed.
        </div>

        <div class="bg-blue-50 border border-blue-200 rounded p-3 text-sm text-blue-800">
            Verified artifacts: <strong>{{ $verifiedCount }}</strong> / {{ $artifacts->total() }} total.
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Artifact ID</th>
                        <th class="px-4 py-2 text-left">Type</th>
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Hash</th>
                        <th class="px-4 py-2 text-left">Size (KB)</th>
                        <th class="px-4 py-2 text-left">Verified</th>
                        <th class="px-4 py-2 text-left">RC Version</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($artifacts as $artifact)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($artifact->artifact_report_id, 0, 18) }}…</td>
                            <td class="px-4 py-2">{{ $artifact->artifact_type }}</td>
                            <td class="px-4 py-2">
                                <span class="px-2 py-0.5 rounded text-xs {{ $artifact->artifact_state === 'validated' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                    {{ $artifact->artifact_state }}
                                </span>
                            </td>
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($artifact->artifact_hash, 0, 12) }}…</td>
                            <td class="px-4 py-2">{{ number_format($artifact->artifact_size_kb) }}</td>
                            <td class="px-4 py-2 {{ $artifact->integrity_verified ? 'text-green-600' : 'text-red-500' }}">{{ $artifact->integrity_verified ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $artifact->rc_version ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $artifact->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-4 text-center text-gray-400 text-sm">No artifacts generated.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $artifacts->links() }}</div>

    </div>
</x-app-layout>
