<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Enterprise Operations Audit Timeline</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 mb-4 text-sm text-yellow-800">
            advisory-only — Operations audit is append-only and operator-visible. No hidden operational automation.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-2 text-left">Operation</th>
                    <th class="px-4 py-2 text-left">Scope</th>
                    <th class="px-4 py-2 text-left">Actor</th>
                    <th class="px-4 py-2 text-left">Outcome</th>
                    <th class="px-4 py-2 text-left">Time</th>
                </tr></thead>
                <tbody>
                @forelse($events as $e)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $e->operation_type }}</td>
                        <td class="px-4 py-2">{{ $e->service_scope }}</td>
                        <td class="px-4 py-2">{{ $e->actor }}</td>
                        <td class="px-4 py-2 {{ $e->outcome === 'success' ? 'text-green-600' : ($e->outcome === 'failure' ? 'text-red-600' : 'text-yellow-600') }}">{{ $e->outcome }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $e->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-4 text-center text-gray-400">No records</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $events->links() }}</div>
    </div>
</x-app-layout>
