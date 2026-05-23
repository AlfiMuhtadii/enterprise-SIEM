<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Release Governance Timeline</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-blue-50 border border-blue-300 rounded p-3 mb-6 text-sm text-blue-800">
            Commercial readiness workflows are bounded, replay-safe, and audit-visible. No destructive support action, hidden telemetry collection, or autonomous remediation is executed.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">Release ID</th>
                        <th class="px-4 py-2 text-left">Version</th>
                        <th class="px-4 py-2 text-left">Stage</th>
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Migration Safe</th>
                        <th class="px-4 py-2 text-left">Rollback Safe</th>
                        <th class="px-4 py-2 text-left">Self-Approve Blocked</th>
                        <th class="px-4 py-2 text-left">Released</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($releases as $release)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $release->release_id }}</td>
                        <td class="px-4 py-2 font-mono">{{ $release->release_version }}</td>
                        <td class="px-4 py-2">{{ strtoupper($release->release_stage) }}</td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 rounded text-xs {{ $release->release_state === 'published' ? 'bg-green-100 text-green-800' : ($release->release_state === 'deprecated' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800') }}">
                                {{ $release->release_state }}
                            </span>
                        </td>
                        <td class="px-4 py-2">{{ $release->migration_compatible ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2">{{ $release->rollback_compatible ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2 text-green-700 font-semibold">{{ $release->self_approve_blocked ? 'Enforced' : '—' }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $release->created_at->diffForHumans() }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $releases->links() }}</div>
    </div>
</x-app-layout>
