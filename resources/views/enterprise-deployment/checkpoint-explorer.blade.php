<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Rollout Checkpoint Explorer</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 mb-4 text-sm text-yellow-800">
            advisory-only — Checkpoint sequencing is deterministic. No autonomous rollout continuation.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-2 text-left">Rollout ID</th>
                    <th class="px-4 py-2 text-left">Type</th>
                    <th class="px-4 py-2 text-left">Passed</th>
                    <th class="px-4 py-2 text-left">Recorded</th>
                </tr></thead>
                <tbody>
                @forelse($checkpoints as $c)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $c->rollout_id }}</td>
                        <td class="px-4 py-2">{{ $c->checkpoint_type }}</td>
                        <td class="px-4 py-2 {{ $c->passed ? 'text-green-600' : 'text-red-600' }}">
                            {{ $c->passed ? 'Pass' : 'Fail' }}
                        </td>
                        <td class="px-4 py-2 text-gray-500">{{ $c->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-4 text-center text-gray-400">No records</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $checkpoints->links() }}</div>
    </div>
</x-app-layout>
