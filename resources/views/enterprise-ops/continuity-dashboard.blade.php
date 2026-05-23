<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Operational Continuity Dashboard</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 mb-4 text-sm text-yellow-800">
            advisory-only — Continuity scoring is deterministic and replay-safe. No autonomous remediation.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-2 text-left">Environment</th>
                    <th class="px-4 py-2 text-left">Score</th>
                    <th class="px-4 py-2 text-left">Replay</th>
                    <th class="px-4 py-2 text-left">Queue</th>
                    <th class="px-4 py-2 text-left">Overall</th>
                </tr></thead>
                <tbody>
                @forelse($reports as $r)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $r->environment }}</td>
                        <td class="px-4 py-2">{{ number_format($r->continuity_score * 100, 1) }}%</td>
                        <td class="px-4 py-2">{{ $r->replay_continuous ? 'OK' : 'Fail' }}</td>
                        <td class="px-4 py-2">{{ $r->queue_continuous ? 'OK' : 'Fail' }}</td>
                        <td class="px-4 py-2 font-semibold {{ $r->overall_continuous ? 'text-green-600' : 'text-red-600' }}">
                            {{ $r->overall_continuous ? 'PASS' : 'FAIL' }}
                        </td>
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
