<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Deployment Package Explorer</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 mb-4 text-sm text-yellow-800">
            advisory-only — Package validation is bounded and replay-safe. No unsigned deployment acceptance.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-2 text-left">Package ID</th>
                    <th class="px-4 py-2 text-left">Version</th>
                    <th class="px-4 py-2 text-left">Signed</th>
                    <th class="px-4 py-2 text-left">Dep Integrity</th>
                    <th class="px-4 py-2 text-left">Recorded</th>
                </tr></thead>
                <tbody>
                @forelse($manifests as $m)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $m->package_id }}</td>
                        <td class="px-4 py-2">{{ $m->version }}</td>
                        <td class="px-4 py-2">{{ $m->is_signed ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2">{{ $m->dependency_integrity_verified ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $m->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-4 text-center text-gray-400">No records</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $manifests->links() }}</div>
    </div>
</x-app-layout>
