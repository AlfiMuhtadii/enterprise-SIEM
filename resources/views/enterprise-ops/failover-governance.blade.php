<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Failover Governance Console</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 mb-4 text-sm text-yellow-800">
            advisory-only — Failover validation is bounded and deterministic. No uncontrolled failover execution.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-2 text-left">Service</th>
                    <th class="px-4 py-2 text-left">Type</th>
                    <th class="px-4 py-2 text-left">Ready</th>
                    <th class="px-4 py-2 text-left">Rollback Ready</th>
                    <th class="px-4 py-2 text-left">Continuity</th>
                </tr></thead>
                <tbody>
                @forelse($runs as $r)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $r->service_name }}</td>
                        <td class="px-4 py-2">{{ $r->failover_type }}</td>
                        <td class="px-4 py-2">{{ $r->readiness_verified ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2">{{ $r->rollback_ready ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2">{{ $r->continuity_verified ? 'Yes' : 'No' }}</td>
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
