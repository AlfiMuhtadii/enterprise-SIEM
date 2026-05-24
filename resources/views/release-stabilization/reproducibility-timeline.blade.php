<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Deployment Reproducibility Timeline
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> Release stabilization workflows are bounded, replay-safe, and audit-visible.
            No destructive deployment automation, uncontrolled feature expansion, or autonomous remediation is executed.
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Report ID</th>
                        <th class="px-4 py-2 text-left">Scope</th>
                        <th class="px-4 py-2 text-left">Score</th>
                        <th class="px-4 py-2 text-left">Env Match</th>
                        <th class="px-4 py-2 text-left">Hash OK</th>
                        <th class="px-4 py-2 text-left">Config Det.</th>
                        <th class="px-4 py-2 text-left">Replay OK</th>
                        <th class="px-4 py-2 text-left">RC Version</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($reports as $report)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($report->reproducibility_report_id, 0, 16) }}…</td>
                            <td class="px-4 py-2">{{ $report->deployment_scope }}</td>
                            <td class="px-4 py-2">{{ number_format($report->reproducibility_score * 100, 1) }}%</td>
                            <td class="px-4 py-2 {{ $report->environment_matched ? 'text-green-600' : 'text-red-500' }}">{{ $report->environment_matched ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 {{ $report->artifact_hash_verified ? 'text-green-600' : 'text-red-500' }}">{{ $report->artifact_hash_verified ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 {{ $report->configuration_deterministic ? 'text-green-600' : 'text-red-500' }}">{{ $report->configuration_deterministic ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 {{ $report->replay_continuity_verified ? 'text-green-600' : 'text-red-500' }}">{{ $report->replay_continuity_verified ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $report->rc_version ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $report->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-4 text-center text-gray-400 text-sm">No reproducibility reports yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $reports->links() }}</div>

    </div>
</x-app-layout>
