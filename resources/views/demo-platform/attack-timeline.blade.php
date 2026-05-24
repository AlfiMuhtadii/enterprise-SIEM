<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Guided Attack Timeline Viewer</h2>
    </x-slot>
    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> All demonstrations are synthetic, replay-safe, advisory-only, and bounded.
            No destructive exploitation or autonomous remediation is executed.
        </div>
        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Scenario</th>
                        <th class="px-4 py-2 text-left">Events</th>
                        <th class="px-4 py-2 text-left">Duration (ms)</th>
                        <th class="px-4 py-2 text-left">Deterministic</th>
                        <th class="px-4 py-2 text-left">Real Malware</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($runs as $run)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ $run->scenario_name }}</td>
                            <td class="px-4 py-2">{{ $run->event_count }}</td>
                            <td class="px-4 py-2">{{ number_format($run->run_duration_ms, 2) }}</td>
                            <td class="px-4 py-2 text-green-600">{{ $run->is_deterministic ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 text-green-600">{{ $run->has_real_malware ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $run->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-4 text-center text-gray-400 text-sm">No runs yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $runs->links() }}</div>
    </div>
</x-app-layout>
