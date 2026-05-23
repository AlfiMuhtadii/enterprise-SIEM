<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Support Diagnostics Dashboard</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-blue-50 border border-blue-300 rounded p-3 mb-6 text-sm text-blue-800">
            Commercial readiness workflows are bounded, replay-safe, and audit-visible. No destructive support action, hidden telemetry collection, or autonomous remediation is executed.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">Bundle ID</th>
                        <th class="px-4 py-2 text-left">Type</th>
                        <th class="px-4 py-2 text-left">Scope</th>
                        <th class="px-4 py-2 text-left">Size (MB)</th>
                        <th class="px-4 py-2 text-left">Bounded</th>
                        <th class="px-4 py-2 text-left">Destructive</th>
                        <th class="px-4 py-2 text-left">Replay Safe</th>
                        <th class="px-4 py-2 text-left">Exported</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($bundles as $bundle)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $bundle->bundle_export_id }}</td>
                        <td class="px-4 py-2">{{ $bundle->bundle_type }}</td>
                        <td class="px-4 py-2">{{ $bundle->export_scope }}</td>
                        <td class="px-4 py-2">{{ number_format($bundle->export_size_mb, 1) }}</td>
                        <td class="px-4 py-2 text-green-700">{{ $bundle->size_bounded ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2 text-{{ $bundle->destructive_action_taken ? 'red' : 'green' }}-700">
                            {{ $bundle->destructive_action_taken ? 'Yes' : 'No' }}
                        </td>
                        <td class="px-4 py-2">{{ $bundle->replay_safe ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $bundle->created_at->diffForHumans() }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $bundles->links() }}</div>
    </div>
</x-app-layout>
