<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Domain Soak Simulations</h2>
        <p class="text-xs text-amber-400/80 mt-1">ENTERPRISE-057 — Advisory-only. Structural validation for endpoint/network/threat-intel shadow domains. SIMULATION ≠ real soak.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-4">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory:</strong> promotion_recommended = false (always). Real 6h domain soak required before any promotion. Simulation validates structural rule coverage only.
        </div>
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-300 mb-2">Simulation Runs</h3>
            @if($simulations->isEmpty())
                <p class="text-gray-500 text-xs">No simulations run yet. Run <code class="text-cyan-400">php artisan domain:soak-simulate</code>.</p>
            @else
            <table class="w-full text-xs text-gray-300">
                <thead><tr class="border-b border-white/10 text-gray-400">
                    <th class="text-left py-2 pr-3">Domain</th>
                    <th class="text-left py-2 pr-3">Rules</th>
                    <th class="text-left py-2 pr-3">Match Rate</th>
                    <th class="text-left py-2 pr-3">FP Est.</th>
                    <th class="text-left py-2 pr-3">Verdict</th>
                    <th class="text-left py-2">Promo?</th>
                </tr></thead>
                <tbody>
                @foreach($simulations as $s)
                    @php $s = (array) $s; @endphp
                    <tr class="border-b border-white/5">
                        <td class="py-1 pr-3 text-cyan-300">{{ $s['domain'] ?? '' }}</td>
                        <td class="py-1 pr-3">{{ $s['rules_total'] ?? 0 }}</td>
                        <td class="py-1 pr-3 {{ (($s['structural_match_rate'] ?? 0) >= 0.80) ? 'text-green-400' : 'text-yellow-400' }}">
                            {{ number_format(($s['structural_match_rate'] ?? 0) * 100, 1) }}%
                        </td>
                        <td class="py-1 pr-3 text-gray-300">{{ number_format(($s['fp_estimate_rate'] ?? 0) * 100, 1) }}%</td>
                        <td class="py-1 pr-3 text-amber-400 font-mono text-xs">{{ $s['soak_verdict'] ?? 'SIMULATION_ONLY' }}</td>
                        <td class="py-1 text-red-400 font-mono text-xs">BLOCKED</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>
        <div class="glass-card p-4 text-xs text-gray-400">
            <p>Supported domains: <span class="text-cyan-300">endpoint, network, threat-intel</span></p>
            <p class="mt-1">Pass criteria: structural_match_rate ≥ 80%, fp_estimate &lt; 10%. Simulation PASS ≠ promotion approved.</p>
        </div>
    </div>
</x-app-layout>
