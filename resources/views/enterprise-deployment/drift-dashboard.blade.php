<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Deployment Drift Dashboard</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 mb-4 text-sm text-yellow-800">
            advisory-only — Drift detection is bounded and operator-visible. No autonomous remediation.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-2 text-left">Service</th>
                    <th class="px-4 py-2 text-left">Expected</th>
                    <th class="px-4 py-2 text-left">Actual</th>
                    <th class="px-4 py-2 text-left">Severity</th>
                    <th class="px-4 py-2 text-left">Score</th>
                </tr></thead>
                <tbody>
                @forelse($reports as $r)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $r->service }}</td>
                        <td class="px-4 py-2">{{ $r->expected_version }}</td>
                        <td class="px-4 py-2">{{ $r->actual_version }}</td>
                        <td class="px-4 py-2">{{ $r->drift_severity }}</td>
                        <td class="px-4 py-2">{{ number_format($r->drift_score, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-4 text-center text-gray-400">No records</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $reports->links() }}</div>
    </div>
</x-app-layout>
