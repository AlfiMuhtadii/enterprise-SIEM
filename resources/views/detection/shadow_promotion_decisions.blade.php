<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Shadow Promotion Decisions</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">ENTERPRISE-047 — Advisory-only. promotion_approved = false always. Run <code>php artisan shadow:evaluate-promotion</code> to refresh.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Gate Enforcement:</strong> promote_eligible decisions are advisory signals only. Actual promotion requires:
            (1) domain-specific 6h soak PASS, (2) ACTIVE_ALLOWLIST update in xdr_rule_registry_validate.py, (3) detection-engineering analyst sign-off.
            This view does NOT trigger any promotion.
        </div>

        @if($results->isEmpty())
            <div class="glass-card p-6 text-center text-gray-500">
                No evaluation runs found. Run <code class="text-cyan-400">php artisan shadow:evaluate-promotion</code> to populate decisions.
            </div>
        @else

        {{-- Summary counts --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-gray-300">{{ $summary['total'] }}</div>
                <div class="text-xs text-gray-400 mt-1">Total Evaluated</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-cyan-400">{{ $summary['promote_eligible'] }}</div>
                <div class="text-xs text-gray-400 mt-1">Promote Eligible</div>
                <div class="text-xs text-amber-400/70 mt-1">Advisory only</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-yellow-400">{{ $summary['keep_shadow'] }}</div>
                <div class="text-xs text-gray-400 mt-1">Keep Shadow</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-orange-400">{{ $summary['defer'] }}</div>
                <div class="text-xs text-gray-400 mt-1">Deferred</div>
            </div>
        </div>

        {{-- Thresholds legend --}}
        <div class="glass-card p-4 text-xs space-y-1 text-gray-300">
            <div><span class="text-cyan-400 font-mono">promote_eligible</span> — confidence &ge; 0.78 AND DLQ errors in domain = 0</div>
            <div><span class="text-yellow-400 font-mono">keep_shadow</span> — confidence &ge; 0.65 (but below threshold or DLQ errors &gt; 0)</div>
            <div><span class="text-orange-400 font-mono">defer</span> — confidence &lt; 0.65; insufficient signal; requires rule tuning</div>
        </div>

        {{-- Decision table --}}
        <div class="glass-card overflow-hidden">
            <table class="w-full text-xs text-gray-300">
                <thead>
                    <tr class="border-b border-gray-700/50 text-gray-500 uppercase text-xs">
                        <th class="px-4 py-3 text-left">Rule ID</th>
                        <th class="px-4 py-3 text-left">Domain</th>
                        <th class="px-4 py-3 text-right">Confidence</th>
                        <th class="px-4 py-3 text-right">DLQ Errors</th>
                        <th class="px-4 py-3 text-center">FP Risk</th>
                        <th class="px-4 py-3 text-center">Decision</th>
                        <th class="px-4 py-3 text-center">Approved</th>
                        <th class="px-4 py-3 text-left">Evaluated At</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($results as $row)
                    @php
                        $decisionColor = match($row['decision'] ?? '') {
                            'promote_eligible' => 'text-cyan-400',
                            'keep_shadow'      => 'text-yellow-400',
                            'defer'            => 'text-orange-400',
                            default            => 'text-gray-500',
                        };
                        $fpColor = match($row['false_positive_risk'] ?? '') {
                            'low'    => 'text-green-400',
                            'medium' => 'text-yellow-400',
                            'high'   => 'text-red-400',
                            default  => 'text-gray-500',
                        };
                    @endphp
                    <tr class="border-b border-gray-800/50 hover:bg-gray-800/20">
                        <td class="px-4 py-2 font-mono text-xs">{{ $row['rule_id'] }}</td>
                        <td class="px-4 py-2">{{ $row['domain'] }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format((float)($row['confidence'] ?? 0), 2) }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['dlq_errors_in_domain'] ?? 0 }}</td>
                        <td class="px-4 py-2 text-center {{ $fpColor }}">{{ $row['false_positive_risk'] ?? 'unknown' }}</td>
                        <td class="px-4 py-2 text-center {{ $decisionColor }} font-medium">{{ $row['decision'] ?? '' }}</td>
                        <td class="px-4 py-2 text-center text-gray-500">false</td>
                        <td class="px-4 py-2 text-gray-500 font-mono text-xs">{{ $row['evaluated_at'] ?? '' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @endif
    </div>
</x-app-layout>
