<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Deployment Observability Viewer</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 mb-4 text-sm text-yellow-800">
            advisory-only — Observability snapshots are replay-safe. No destructive metric mutation.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-2 text-left">Deployment ID</th>
                    <th class="px-4 py-2 text-left">Success Rate</th>
                    <th class="px-4 py-2 text-left">Duration (s)</th>
                    <th class="px-4 py-2 text-left">Replay Verified</th>
                </tr></thead>
                <tbody>
                @forelse($snapshots as $s)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $s->deployment_id }}</td>
                        <td class="px-4 py-2">{{ number_format($s->success_rate * 100, 1) }}%</td>
                        <td class="px-4 py-2">{{ $s->rollout_duration_s }}</td>
                        <td class="px-4 py-2">{{ $s->replay_continuity_verified ? 'Yes' : 'No' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-4 text-center text-gray-400">No records</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $snapshots->links() }}</div>
    </div>
</x-app-layout>
