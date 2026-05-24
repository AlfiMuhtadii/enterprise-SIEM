<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Executive Readiness Reports
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> Executive reports are advisory and informational. Self-approve blocked. No autonomous go-live authorization.
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Report ID</th>
                        <th class="px-4 py-2 text-left">Scope</th>
                        <th class="px-4 py-2 text-left">Overall Score</th>
                        <th class="px-4 py-2 text-left">Gates</th>
                        <th class="px-4 py-2 text-left">Open Limits</th>
                        <th class="px-4 py-2 text-left">High Risks</th>
                        <th class="px-4 py-2 text-left">Recommendation</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($reports as $report)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($report->exec_report_id, 0, 20) }}…</td>
                            <td class="px-4 py-2">{{ $report->report_scope }}</td>
                            <td class="px-4 py-2">{{ number_format($report->overall_readiness_score * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ $report->gates_passed }}/{{ $report->gates_total }}</td>
                            <td class="px-4 py-2">{{ $report->limitations_open }}</td>
                            <td class="px-4 py-2">{{ $report->risks_high }}</td>
                            <td class="px-4 py-2">
                                <span class="px-2 py-0.5 rounded text-xs {{ $report->go_live_recommendation === 'approved' ? 'bg-green-100 text-green-700' : ($report->go_live_recommendation === 'blocked' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700') }}">
                                    {{ $report->go_live_recommendation }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $report->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-4 text-center text-gray-400 text-sm">No executive reports generated.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $reports->links() }}</div>

    </div>
</x-app-layout>
