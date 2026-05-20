<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Detection Quality Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Detection lifecycle actions are governed, replay-validated, and do not execute autonomous response.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> Quality scores are deterministic and evidence-based. No automatic demotion or promotion is triggered by quality score changes.
        </div>

        <div class="grid grid-cols-4 gap-4">
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 mb-1">FP Reports (7d)</div>
                <div class="text-2xl font-bold text-red-300">{{ $stats['fp_reports_7d'] }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 mb-1">Active Suppressions</div>
                <div class="text-2xl font-bold text-orange-300">{{ $stats['active_suppressions'] }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 mb-1">Replay Pass Rate</div>
                <div class="text-2xl font-bold {{ $stats['replay_pass_rate'] >= 0.9 ? 'text-green-400' : 'text-yellow-400' }}">{{ number_format($stats['replay_pass_rate'] * 100, 1) }}%</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 mb-1">Pending Promotions</div>
                <div class="text-2xl font-bold text-yellow-300">{{ $stats['pending_promotions'] }}</div>
            </div>
        </div>

        <div class="space-y-2">
            <h3 class="text-sm font-semibold text-cyan-200">Rule Quality Scores (lowest first)</h3>
            @forelse($metrics as $m)
            <div class="glass-card p-3 flex items-center justify-between text-xs">
                <span class="font-mono text-gray-300">{{ $m->rule_id }}</span>
                <div class="flex gap-4 text-gray-400">
                    <span>Score: <span class="{{ $m->quality_score >= 0.8 ? 'text-green-300' : ($m->quality_score >= 0.6 ? 'text-yellow-300' : 'text-red-300') }}">{{ number_format($m->quality_score, 3) }}</span></span>
                    <span>Trend: <span class="{{ $m->quality_trend === 'improving' ? 'text-green-300' : ($m->quality_trend === 'degrading' ? 'text-red-300' : 'text-gray-400') }}">{{ $m->quality_trend }}</span></span>
                    <span>FP 7d: {{ $m->fp_report_count }}</span>
                    <span>Replays: {{ $m->replay_pass_count }}/{{ $m->replay_pass_count + $m->replay_fail_count }}</span>
                </div>
            </div>
            @empty
            <p class="text-xs text-gray-500">No quality metrics computed yet. Run computeQualityMetric() for each rule.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
