<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Analyst Triage Simulation Viewer
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> Code-level maturity workflows are synthetic, replay-safe, advisory-only, and bounded.
            No destructive execution, autonomous remediation, or real exploit activity is executed.
        </div>

        <div class="bg-gray-50 border border-gray-200 rounded p-3 text-sm text-gray-700">
            Simulated analyst actions only. No real user mutation. No automatic incident closure. No hidden suppression.
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Scenario</th>
                        <th class="px-4 py-2 text-left">Alerts</th>
                        <th class="px-4 py-2 text-left">Duplicates</th>
                        <th class="px-4 py-2 text-left">Chains</th>
                        <th class="px-4 py-2 text-left">Escalations</th>
                        <th class="px-4 py-2 text-left">Dismissals</th>
                        <th class="px-4 py-2 text-left">FP Marked</th>
                        <th class="px-4 py-2 text-left">Efficiency</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($runs as $run)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2">{{ $run->simulation_scenario }}</td>
                            <td class="px-4 py-2">{{ $run->alerts_simulated }}</td>
                            <td class="px-4 py-2">{{ $run->duplicates_handled }}</td>
                            <td class="px-4 py-2">{{ $run->chains_investigated }}</td>
                            <td class="px-4 py-2">{{ $run->escalations_decided }}</td>
                            <td class="px-4 py-2">{{ $run->dismissals_decided }}</td>
                            <td class="px-4 py-2">{{ $run->fp_markings }}</td>
                            <td class="px-4 py-2 {{ $run->triage_efficiency_score >= 0.75 ? 'text-green-600' : 'text-yellow-600' }}">
                                {{ number_format($run->triage_efficiency_score * 100, 1) }}%
                            </td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $run->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-4 text-center text-gray-400 text-sm">No triage simulations yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $runs->links() }}</div>

    </div>
</x-app-layout>
