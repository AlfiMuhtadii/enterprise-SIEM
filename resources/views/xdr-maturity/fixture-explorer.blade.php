<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Synthetic Attack Fixture Explorer
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> Code-level maturity workflows are synthetic, replay-safe, advisory-only, and bounded.
            No destructive execution, autonomous remediation, or real exploit activity is executed.
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
                        <th class="px-4 py-2 text-left">Trace ID</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($fixtures as $f)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ $f->fixture_scenario }}</td>
                            <td class="px-4 py-2">
                                <span class="px-2 py-0.5 rounded text-xs {{ $f->fixture_state === 'validated' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                    {{ $f->fixture_state }}
                                </span>
                            </td>
                            <td class="px-4 py-2">{{ $f->total_events }}</td>
                            <td class="px-4 py-2 text-green-600">{{ $f->is_lab_safe ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 text-green-600">{{ $f->is_destructive ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($f->trace_id, 0, 16) }}…</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $f->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-4 text-center text-gray-400 text-sm">No fixtures yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $fixtures->links() }}</div>

    </div>
</x-app-layout>
