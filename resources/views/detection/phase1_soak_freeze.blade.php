<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Phase 1 Soak Evidence Freeze</h2>
        <p class="text-xs text-amber-400/80 mt-1">ENTERPRISE-064 — Advisory-only. freeze_approved = false always. NO_PROMOTION = true.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-4">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory:</strong> This freeze record is an immutable evidence snapshot only.
            <code class="text-xs ml-1">freeze_approved = false</code> — no freeze run authorizes rule promotion.
            Real 6h soak PASS via <code class="text-xs">run_xdr_correlation_soak_6h.ps1</code> is required before any promotion gate opens.
        </div>

        {{-- Score cards --}}
        <div class="grid grid-cols-4 gap-4">
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold {{ ($summary['pass_score'] ?? 0) >= 0.80 ? 'text-green-400' : 'text-yellow-400' }}">
                    {{ number_format(($summary['pass_score'] ?? 0) * 100, 1) }}%
                </div>
                <div class="text-xs text-gray-400 mt-1">Pass Score</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-cyan-400">{{ $summary['gates_passed'] ?? 0 }}/{{ $summary['gates_total'] ?? 12 }}</div>
                <div class="text-xs text-gray-400 mt-1">Gates Passed</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold {{ ($summary['verdict'] ?? 'NO_RUN') === 'PASS' ? 'text-green-400' : (($summary['verdict'] ?? 'NO_RUN') === 'FAIL' ? 'text-red-400' : 'text-yellow-400') }}">
                    {{ $summary['verdict'] ?? 'NO_RUN' }}
                </div>
                <div class="text-xs text-gray-400 mt-1">Verdict</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-red-400">false</div>
                <div class="text-xs text-gray-400 mt-1">Freeze Approved</div>
            </div>
        </div>

        {{-- Gate table --}}
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-300 mb-2">Freeze Gates (EV064-01 – EV064-12)</h3>
            @if(count($gates) === 0)
                <p class="text-xs text-gray-500">No freeze run persisted yet. Run <code>php artisan soak:phase1-freeze</code> to generate.</p>
            @else
            <table class="w-full text-xs text-gray-300">
                <thead><tr class="border-b border-white/10 text-gray-400">
                    <th class="text-left py-2 pr-3">Gate</th>
                    <th class="text-left py-2 pr-3">Status</th>
                    <th class="text-left py-2 pr-3">Advisory</th>
                    <th class="text-left py-2">Evidence</th>
                </tr></thead>
                <tbody>
                @foreach($gates as $g)
                    @php $g = (array) $g; @endphp
                    <tr class="border-b border-white/5">
                        <td class="py-1 pr-3 font-mono text-gray-400">{{ $g['gate_id'] }}</td>
                        <td class="py-1 pr-3">
                            @if($g['status'] === 'pass')
                                <span class="text-green-400 font-semibold">PASS</span>
                            @elseif($g['status'] === 'fail')
                                <span class="text-red-400 font-semibold">FAIL</span>
                            @else
                                <span class="text-yellow-400 font-semibold">WARN</span>
                            @endif
                        </td>
                        <td class="py-1 pr-3 text-gray-500">{{ $g['is_advisory'] ? 'yes' : 'no' }}</td>
                        <td class="py-1 text-gray-400">{{ $g['gate_name'] }} — {{ $g['evidence'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>

        {{-- Evidence snapshot --}}
        @if(count($evidence) > 0)
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-300 mb-2">Evidence Snapshot</h3>
            <table class="w-full text-xs text-gray-300">
                <thead><tr class="border-b border-white/10 text-gray-400">
                    <th class="text-left py-2 pr-3">Type</th>
                    <th class="text-left py-2 pr-3">Value</th>
                    <th class="text-left py-2">Source</th>
                </tr></thead>
                <tbody>
                @foreach($evidence as $ev)
                    @php $ev = (array) $ev; @endphp
                    <tr class="border-b border-white/5">
                        <td class="py-1 pr-3 font-mono text-gray-400">{{ $ev['evidence_type'] }}</td>
                        <td class="py-1 pr-3 text-white font-semibold">{{ $ev['evidence_value'] }}</td>
                        <td class="py-1 text-gray-500">{{ $ev['source_table'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif

        {{-- Safety invariants --}}
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-gray-300 mb-2">Safety Invariants</h3>
            <ul class="text-xs text-gray-400 space-y-1">
                <li>✓ <code>NO_PROMOTION = true</code> — no freeze run authorizes rule promotion</li>
                <li>✓ <code>FREEZE_APPROVED = false</code> — documentation record only</li>
                <li>✓ <code>ADVISORY_ONLY = true</code> — all outputs are advisory</li>
                <li>✓ Freeze tables are append-only — rows are never updated or deleted</li>
                <li>✗ Real 6h soak via <code>run_xdr_correlation_soak_6h.ps1</code> still required before promotion</li>
            </ul>
        </div>

    </div>
</x-app-layout>
