<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Noisy Enterprise Simulation Viewer
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
                        <th class="px-4 py-2 text-left">Events</th>
                        <th class="px-4 py-2 text-left">FP Resistance</th>
                        <th class="px-4 py-2 text-left">Amp Score</th>
                        <th class="px-4 py-2 text-left">Pressure</th>
                        <th class="px-4 py-2 text-left">Telemetry Quality</th>
                        <th class="px-4 py-2 text-left">Lab Safe</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($simulations as $sim)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2">{{ $sim->noise_scenario }}</td>
                            <td class="px-4 py-2">{{ number_format($sim->events_generated) }}</td>
                            <td class="px-4 py-2 {{ $sim->fp_resistance_score >= 0.75 ? 'text-green-600' : 'text-yellow-600' }}">
                                {{ number_format($sim->fp_resistance_score * 100, 1) }}%
                            </td>
                            <td class="px-4 py-2">{{ number_format($sim->alert_amplification_score * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ number_format($sim->analyst_pressure_score * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ number_format($sim->telemetry_quality_under_noise * 100, 1) }}%</td>
                            <td class="px-4 py-2 text-green-600">{{ $sim->is_lab_safe ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $sim->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-4 text-center text-gray-400 text-sm">No noisy simulations yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $simulations->links() }}</div>

    </div>
</x-app-layout>
