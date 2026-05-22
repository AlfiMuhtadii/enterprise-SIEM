<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Upgrade Governance Timeline</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 mb-4 text-sm text-yellow-800">
            advisory-only — Upgrade lineage is deterministic and replay-safe. No unsafe upgrade bypass.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-2 text-left">From</th>
                    <th class="px-4 py-2 text-left">To</th>
                    <th class="px-4 py-2 text-left">Migration</th>
                    <th class="px-4 py-2 text-left">Rollback Ready</th>
                    <th class="px-4 py-2 text-left">Compatible</th>
                </tr></thead>
                <tbody>
                @forelse($upgrades as $u)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $u->from_version }}</td>
                        <td class="px-4 py-2">{{ $u->to_version }}</td>
                        <td class="px-4 py-2">{{ $u->migration_passed ? 'Pass' : 'Fail' }}</td>
                        <td class="px-4 py-2">{{ $u->rollback_compatible ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2">{{ $u->compatibility_verified ? 'Yes' : 'No' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-4 text-center text-gray-400">No records</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $upgrades->links() }}</div>
    </div>
</x-app-layout>
