<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Rollout Validation Viewer</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 mb-4 text-sm text-yellow-800">
            advisory-only — Rollout concurrency is bounded. No uncontrolled rollout or autonomous deployment.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-2 text-left">Rollout ID</th>
                    <th class="px-4 py-2 text-left">Stage</th>
                    <th class="px-4 py-2 text-left">Canary Endpoints</th>
                    <th class="px-4 py-2 text-left">Success</th>
                    <th class="px-4 py-2 text-left">Duration (s)</th>
                </tr></thead>
                <tbody>
                @forelse($runs as $r)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $r->rollout_id }}</td>
                        <td class="px-4 py-2">{{ $r->stage }}</td>
                        <td class="px-4 py-2">{{ $r->canary_endpoints }}</td>
                        <td class="px-4 py-2">{{ $r->rollout_success ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2">{{ $r->rollout_duration_s }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-4 text-center text-gray-400">No records</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $runs->links() }}</div>
    </div>
</x-app-layout>
