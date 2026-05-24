<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Demo Readiness Dashboard</h2>
    </x-slot>
    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> All demonstrations are synthetic, replay-safe, advisory-only, and bounded.
            No destructive exploitation or autonomous remediation is executed.
        </div>
        <div class="bg-white rounded shadow p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Environment Validation</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                @foreach($env['table_checks'] as $table => $exists)
                    <div class="flex items-center gap-2">
                        <span class="{{ $exists ? 'text-green-600' : 'text-red-500' }}">{{ $exists ? '✓' : '✗' }}</span>
                        <span class="font-mono text-xs">{{ $table }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Snapshot ID</th>
                        <th class="px-4 py-2 text-left">PHP Tests</th>
                        <th class="px-4 py-2 text-left">Python Tests</th>
                        <th class="px-4 py-2 text-left">Registry</th>
                        <th class="px-4 py-2 text-left">Contracts</th>
                        <th class="px-4 py-2 text-left">Resilience</th>
                        <th class="px-4 py-2 text-left">Fleet Sim</th>
                        <th class="px-4 py-2 text-left">Rules</th>
                        <th class="px-4 py-2 text-left">Domains</th>
                        <th class="px-4 py-2 text-left">Ready</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($snapshots as $s)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($s->snapshot_id, 0, 16) }}…</td>
                            <td class="px-4 py-2">{{ number_format($s->php_tests_passed) }}</td>
                            <td class="px-4 py-2">{{ $s->python_tests_passed }}</td>
                            <td class="px-4 py-2 {{ $s->rule_registry_pass ? 'text-green-600' : 'text-red-500' }}">{{ $s->rule_registry_pass ? 'PASS' : 'FAIL' }}</td>
                            <td class="px-4 py-2 {{ $s->contracts_pass ? 'text-green-600' : 'text-red-500' }}">{{ $s->contracts_pass ? 'PASS' : 'FAIL' }}</td>
                            <td class="px-4 py-2 {{ $s->resilience_pass ? 'text-green-600' : 'text-red-500' }}">{{ $s->resilience_pass ? 'PASS' : 'FAIL' }}</td>
                            <td class="px-4 py-2 {{ $s->fleet_sim_pass ? 'text-green-600' : 'text-red-500' }}">{{ $s->fleet_sim_pass ? 'PASS' : 'FAIL' }}</td>
                            <td class="px-4 py-2">{{ $s->total_rules }}</td>
                            <td class="px-4 py-2">{{ $s->domain_count }}</td>
                            <td class="px-4 py-2 font-semibold {{ $s->overall_ready ? 'text-green-600' : 'text-yellow-600' }}">{{ $s->overall_ready ? 'READY' : 'PENDING' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $s->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="px-4 py-4 text-center text-gray-400 text-sm">No snapshots yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $snapshots->links() }}</div>
    </div>
</x-app-layout>
