<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Recovery Orchestration Viewer</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 mb-4 text-sm text-yellow-800">
            advisory-only — Recovery sequencing is deterministic and bounded. No destructive recovery mutation.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-2 text-left">Service</th>
                    <th class="px-4 py-2 text-left">Type</th>
                    <th class="px-4 py-2 text-left">State</th>
                    <th class="px-4 py-2 text-left">Seq</th>
                    <th class="px-4 py-2 text-left">Duration (s)</th>
                </tr></thead>
                <tbody>
                @forelse($runs as $r)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $r->service_name }}</td>
                        <td class="px-4 py-2">{{ $r->recovery_type }}</td>
                        <td class="px-4 py-2">{{ $r->recovery_state }}</td>
                        <td class="px-4 py-2">{{ $r->sequence_order }}</td>
                        <td class="px-4 py-2">{{ $r->duration_s }}</td>
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
