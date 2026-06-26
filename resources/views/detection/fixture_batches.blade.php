<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Detection Replay Fixtures</h2>
        <p class="text-xs text-amber-400/80 mt-1">ENTERPRISE-056 — Advisory-only. 12 tier_1 fixtures for staged_active rules. Run <code>php artisan rule:run-fixtures</code>.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-4">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory:</strong> Fixtures are synthetic events proving rules fire on known-malicious patterns. promotion_blocked = true. Real soak required before promotion.
        </div>
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-300 mb-2">Fixture Batches</h3>
            @if($batches->isEmpty())
                <p class="text-gray-500 text-xs">No batches run yet. Run <code class="text-cyan-400">php artisan rule:run-fixtures</code>.</p>
            @else
            <table class="w-full text-xs text-gray-300">
                <thead><tr class="border-b border-white/10 text-gray-400">
                    <th class="text-left py-2 pr-3">Batch</th><th class="text-left py-2 pr-3">Tier</th>
                    <th class="text-left py-2 pr-3">Valid</th><th class="text-left py-2 pr-3">Invalid</th><th class="text-left py-2">Run At</th>
                </tr></thead>
                <tbody>
                @foreach($batches as $b)
                    @php $b = (array) $b; @endphp
                    <tr class="border-b border-white/5">
                        <td class="py-1 pr-3 font-mono text-cyan-300 text-xs">{{ substr($b['batch_id'] ?? '', 0, 8) }}…</td>
                        <td class="py-1 pr-3">{{ $b['tier'] ?? '' }}</td>
                        <td class="py-1 pr-3 text-green-400">{{ $b['fixtures_valid'] ?? 0 }}</td>
                        <td class="py-1 pr-3 text-red-400">{{ $b['fixtures_invalid'] ?? 0 }}</td>
                        <td class="py-1 text-gray-400">{{ $b['run_at'] ?? '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>
        @if(!empty($latest))
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-300 mb-2">Latest Batch Results</h3>
            <table class="w-full text-xs text-gray-300">
                <thead><tr class="border-b border-white/10 text-gray-400">
                    <th class="text-left py-2 pr-3">Rule</th><th class="text-left py-2 pr-3">Domain</th>
                    <th class="text-left py-2 pr-3">Valid</th><th class="text-left py-2">Notes</th>
                </tr></thead>
                <tbody>
                @foreach($latest as $r)
                    @php $r = (array) $r; @endphp
                    <tr class="border-b border-white/5">
                        <td class="py-1 pr-3 font-mono text-xs">{{ $r['rule_id'] ?? '' }}</td>
                        <td class="py-1 pr-3">{{ $r['domain'] ?? '' }}</td>
                        <td class="py-1 pr-3 {{ ($r['fixture_valid'] ?? false) ? 'text-green-400' : 'text-red-400' }}">{{ ($r['fixture_valid'] ?? false) ? 'PASS' : 'FAIL' }}</td>
                        <td class="py-1 text-gray-400 text-xs">{{ substr($r['validation_notes'] ?? '', 0, 80) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</x-app-layout>
