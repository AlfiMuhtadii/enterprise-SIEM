<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Performance Hotspot Explorer
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
                        <th class="px-4 py-2 text-left">Report ID</th>
                        <th class="px-4 py-2 text-left">Slow Chains</th>
                        <th class="px-4 py-2 text-left">Exp Graphs</th>
                        <th class="px-4 py-2 text-left">Replay Amp</th>
                        <th class="px-4 py-2 text-left">High Card</th>
                        <th class="px-4 py-2 text-left">Alert Spikes</th>
                        <th class="px-4 py-2 text-left">Exp Hunts</th>
                        <th class="px-4 py-2 text-left">Severity</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($reports as $r)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($r->report_id, 0, 16) }}…</td>
                            <td class="px-4 py-2">{{ $r->slow_chain_count }}</td>
                            <td class="px-4 py-2">{{ $r->expensive_graph_count }}</td>
                            <td class="px-4 py-2">{{ $r->replay_amplification_count }}</td>
                            <td class="px-4 py-2">{{ $r->high_cardinality_count }}</td>
                            <td class="px-4 py-2">{{ $r->alert_spike_count }}</td>
                            <td class="px-4 py-2">{{ $r->expensive_hunt_count }}</td>
                            <td class="px-4 py-2 {{ $r->hotspot_severity_score >= 0.5 ? 'text-red-600' : 'text-green-600' }}">
                                {{ number_format($r->hotspot_severity_score * 100, 1) }}%
                            </td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-4 text-center text-gray-400 text-sm">No hotspot reports yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $reports->links() }}</div>

    </div>
</x-app-layout>
