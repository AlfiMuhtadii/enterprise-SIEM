<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Recovery Simulation Timeline</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 mb-4 text-sm text-yellow-800">
            advisory-only — Simulations are bounded and non-destructive. No infrastructure mutation.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-2 text-left">Type</th>
                    <th class="px-4 py-2 text-left">Target</th>
                    <th class="px-4 py-2 text-left">Duration (s)</th>
                    <th class="px-4 py-2 text-left">Passed</th>
                    <th class="px-4 py-2 text-left">Replay Safe</th>
                </tr></thead>
                <tbody>
                @forelse($simulations as $s)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $s->simulation_type }}</td>
                        <td class="px-4 py-2">{{ $s->target_service }}</td>
                        <td class="px-4 py-2">{{ $s->duration_s }}</td>
                        <td class="px-4 py-2 {{ $s->simulation_passed ? 'text-green-600' : 'text-red-600' }}">{{ $s->simulation_passed ? 'Pass' : 'Fail' }}</td>
                        <td class="px-4 py-2">{{ $s->replay_safe ? 'Yes' : 'No' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-4 text-center text-gray-400">No records</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $simulations->links() }}</div>
    </div>
</x-app-layout>
