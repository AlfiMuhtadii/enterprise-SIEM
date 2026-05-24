<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            XDR Readiness Report Viewer
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> Code-level maturity workflows are synthetic, replay-safe, advisory-only, and bounded.
            No destructive execution, autonomous remediation, or real exploit activity is executed.
        </div>

        <div class="bg-white rounded shadow p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">XDR Maturity Scorecards</h3>
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Detection</th>
                        <th class="px-4 py-2 text-left">Telemetry</th>
                        <th class="px-4 py-2 text-left">FP/FN</th>
                        <th class="px-4 py-2 text-left">Triage</th>
                        <th class="px-4 py-2 text-left">Perf</th>
                        <th class="px-4 py-2 text-left">Noise</th>
                        <th class="px-4 py-2 text-left">Attack</th>
                        <th class="px-4 py-2 text-left">Overall</th>
                        <th class="px-4 py-2 text-left">Tier</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($scorecards as $sc)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2">{{ number_format($sc->detection_quality_score * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ number_format($sc->telemetry_quality_score * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ number_format($sc->fp_fn_health_score * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ number_format($sc->triage_realism_score * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ number_format($sc->performance_health_score * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ number_format($sc->noise_resilience_score * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ number_format($sc->attack_coverage_score * 100, 1) }}%</td>
                            <td class="px-4 py-2 font-semibold {{ $sc->overall_maturity_score >= 0.75 ? 'text-green-600' : 'text-yellow-600' }}">
                                {{ number_format($sc->overall_maturity_score * 100, 1) }}%
                            </td>
                            <td class="px-4 py-2">
                                <span class="px-2 py-0.5 rounded text-xs bg-indigo-100 text-indigo-700">{{ $sc->maturity_tier }}</span>
                            </td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $sc->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-4 py-4 text-center text-gray-400 text-sm">No maturity scorecards yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="mt-2">{{ $scorecards->links() }}</div>
        </div>

        <div class="bg-white rounded shadow p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Report Artifacts</h3>
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Artifact ID</th>
                        <th class="px-4 py-2 text-left">Type</th>
                        <th class="px-4 py-2 text-left">Advisory</th>
                        <th class="px-4 py-2 text-left">Fabricated</th>
                        <th class="px-4 py-2 text-left">Evidence Linked</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($artifacts as $a)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($a->artifact_id, 0, 16) }}…</td>
                            <td class="px-4 py-2">{{ $a->report_type }}</td>
                            <td class="px-4 py-2 text-green-600">{{ $a->is_advisory ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 text-green-600">{{ $a->is_fabricated ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 text-green-600">{{ $a->evidence_linked ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $a->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-4 text-center text-gray-400 text-sm">No report artifacts yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="mt-2">{{ $artifacts->links() }}</div>
        </div>

    </div>
</x-app-layout>
