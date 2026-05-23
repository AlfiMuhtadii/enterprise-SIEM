<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Recovery Dependency Graph Viewer</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 mb-4 text-sm text-yellow-800">
            advisory-only — Dependency graphs are read-only advisory records. No destructive mutation.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-2 text-left">Root Service</th>
                    <th class="px-4 py-2 text-left">Depth</th>
                    <th class="px-4 py-2 text-left">Total Deps</th>
                    <th class="px-4 py-2 text-left">Circular</th>
                    <th class="px-4 py-2 text-left">All Healthy</th>
                </tr></thead>
                <tbody>
                @forelse($graphs as $g)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $g->root_service }}</td>
                        <td class="px-4 py-2">{{ $g->dependency_depth }}</td>
                        <td class="px-4 py-2">{{ $g->total_dependencies }}</td>
                        <td class="px-4 py-2 {{ $g->circular_dependency_detected ? 'text-red-600 font-semibold' : '' }}">
                            {{ $g->circular_dependency_detected ? 'Yes' : 'No' }}
                        </td>
                        <td class="px-4 py-2 {{ $g->all_dependencies_healthy ? 'text-green-600' : 'text-orange-600' }}">
                            {{ $g->all_dependencies_healthy ? 'Yes' : 'No' }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-4 text-center text-gray-400">No records</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $graphs->links() }}</div>
    </div>
</x-app-layout>
