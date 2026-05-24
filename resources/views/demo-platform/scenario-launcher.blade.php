<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Demo Scenario Launcher</h2>
    </x-slot>
    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> All demonstrations are synthetic, replay-safe, advisory-only, and bounded.
            No destructive exploitation or autonomous remediation is executed.
        </div>
        <div class="bg-white rounded shadow p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Available Scenarios ({{ count($scenarios) }})</h3>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                @foreach($scenarios as $s)
                    <div class="border rounded p-2 text-xs font-mono text-gray-700 bg-gray-50">{{ $s }}</div>
                @endforeach
            </div>
        </div>
        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Scenario</th>
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Events</th>
                        <th class="px-4 py-2 text-left">Lab Safe</th>
                        <th class="px-4 py-2 text-left">Destructive</th>
                        <th class="px-4 py-2 text-left">Demo Mode</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($runs as $run)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ $run->scenario_name }}</td>
                            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs {{ $run->scenario_state === 'completed' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">{{ $run->scenario_state }}</span></td>
                            <td class="px-4 py-2">{{ $run->event_count }}</td>
                            <td class="px-4 py-2 text-green-600">{{ $run->is_lab_safe ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 text-green-600">{{ $run->is_destructive ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 text-green-600">{{ $run->demo_mode ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $run->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-4 text-center text-gray-400 text-sm">No scenario runs yet. Seed via DemoScenarioSeeder.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $runs->links() }}</div>
    </div>
</x-app-layout>
