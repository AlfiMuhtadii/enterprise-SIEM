<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Confidence Source Refresh</h2>
        <p class="text-xs text-amber-400/80 mt-1">ENTERPRISE-058 — Advisory-only. Re-derives confidence_source labels after fixture/evidence changes.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-4">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory:</strong> empirical = fixture + evidence. fixture_tested = fixture only. manual = neither. Run <code>php artisan rule:refresh-confidence</code>.
        </div>
        <div class="grid grid-cols-4 gap-4">
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-green-400">{{ $distribution['empirical'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Empirical</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-cyan-400">{{ $distribution['fixture_tested'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Fixture Tested</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-gray-400">{{ $distribution['manual'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Manual</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-white">{{ $distribution['total'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Total Rules</div>
            </div>
        </div>
        @if($latestRun)
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-300 mb-2">Latest Refresh Run</h3>
            @php $run = (array) $latestRun; @endphp
            <div class="grid grid-cols-3 gap-3 text-xs text-gray-300">
                <div>Run ID: <span class="text-cyan-300 font-mono text-xs">{{ substr($run['refresh_run_id'] ?? '', 0, 8) }}…</span></div>
                <div>Changed: <span class="text-yellow-300">{{ $run['changed_count'] ?? 0 }}</span></div>
                <div>Empirical Rate: <span class="text-green-400">{{ number_format(($run['empirical_rate'] ?? 0) * 100, 1) }}%</span></div>
            </div>
        </div>
        @endif
    </div>
</x-app-layout>
