<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Stability Evidence Freeze v3</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">ENTERPRISE-055 — Advisory-only. Covers E045–E054. freeze_approved = false always. Run <code>php artisan stability:freeze-v3</code> to refresh.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Freeze v3:</strong> Consolidated evidence snapshot across ENTERPRISE-045 through ENTERPRISE-054.
            Evaluates 22 gates, 10 phase summaries, allowed/forbidden claims, and remaining gap registry.
            No rules are promoted. freeze_approved requires human sign-off.
        </div>

        @if(($summary['total_gates'] ?? 0) === 0)
            <div class="glass-card p-6 text-center text-gray-500">
                No freeze run found. Run <code class="text-cyan-400">php artisan stability:freeze-v3</code> to generate.
            </div>
        @else

        @php
            $stability  = $summary['stability'] ?? 'UNKNOWN';
            $score      = $summary['pass_score'] ?? 0;
            $stabColor  = $stability === 'STABLE' ? 'text-green-400' : ($stability === 'UNSTABLE' ? 'text-red-400' : 'text-gray-500');
        @endphp

        {{-- Score cards --}}
        <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold {{ $stabColor }}">{{ $stability }}</div>
                <div class="text-xs text-gray-400 mt-1">Stability</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ number_format((float)$score * 100, 1) }}%</div>
                <div class="text-xs text-gray-400 mt-1">Pass Score</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-green-400">{{ $summary['gates_passed'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Gates Passed</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-blue-400">{{ $summary['total_phases'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Phases Covered</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-emerald-400">{{ $summary['allowed_claim_count'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Allowed Claims</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-red-400">{{ $summary['gap_count'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Remaining Gaps</div>
            </div>
        </div>

        {{-- Gate table --}}
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-300 mb-3">Gate Evidence (EV3-01 – EV3-22)</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-gray-300">
                    <thead><tr class="border-b border-white/10 text-gray-400">
                        <th class="text-left py-2 pr-3">Gate</th>
                        <th class="text-left py-2 pr-3">Status</th>
                        <th class="text-left py-2 pr-3">Name</th>
                        <th class="text-left py-2">Evidence</th>
                    </tr></thead>
                    <tbody>
                    @foreach($gates as $gate)
                        @php
                            $g = (array) $gate;
                            $sc = match($g['status'] ?? '') {
                                'pass' => 'text-green-400', 'fail' => 'text-red-400',
                                'warn' => 'text-yellow-400', default => 'text-gray-400'
                            };
                        @endphp
                        <tr class="border-b border-white/5">
                            <td class="py-1 pr-3 font-mono text-cyan-300">{{ $g['gate_id'] }}</td>
                            <td class="py-1 pr-3 font-bold {{ $sc }}">{{ strtoupper($g['status'] ?? '') }}</td>
                            <td class="py-1 pr-3">{{ $g['gate_name'] }}</td>
                            <td class="py-1 text-gray-400">{{ $g['evidence'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Phase summaries --}}
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-300 mb-3">Phase Summaries (E045–E054)</h3>
            <div class="space-y-2">
            @foreach($phases as $phase)
                @php
                    $p = (array) $phase;
                    $m = is_string($p['metrics'] ?? null) ? json_decode($p['metrics'], true) : (array)($p['metrics'] ?? []);
                @endphp
                <div class="flex flex-wrap gap-x-4 text-xs border-b border-white/5 pb-2">
                    <span class="font-mono text-cyan-400 w-12">{{ $p['enterprise_id'] }}</span>
                    <span class="text-gray-300 w-60">{{ $p['phase_name'] }}</span>
                    <span class="text-gray-400">
                        @foreach($m as $k => $v)
                            <span class="mr-2">{{ $k }}={{ is_bool($v) ? ($v ? 'true' : 'false') : $v }}</span>
                        @endforeach
                    </span>
                </div>
            @endforeach
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            {{-- Allowed claims --}}
            <div class="glass-card p-4">
                <h3 class="text-sm font-semibold text-green-400 mb-3">Allowed Claims</h3>
                <ul class="space-y-1 text-xs text-gray-300">
                @foreach($claims->filter(fn($c) => ((array)$c)['claim_type'] === 'allowed') as $c)
                    <li class="flex gap-2"><span class="text-green-500 shrink-0">✓</span>{{ ((array)$c)['claim_text'] }}</li>
                @endforeach
                </ul>
            </div>

            {{-- Forbidden claims --}}
            <div class="glass-card p-4">
                <h3 class="text-sm font-semibold text-red-400 mb-3">Forbidden Claims</h3>
                <ul class="space-y-1 text-xs text-gray-300">
                @foreach($claims->filter(fn($c) => ((array)$c)['claim_type'] === 'forbidden') as $c)
                    <li class="flex gap-2"><span class="text-red-500 shrink-0">✗</span>{{ ((array)$c)['claim_text'] }}</li>
                @endforeach
                </ul>
            </div>
        </div>

        {{-- Gap registry --}}
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-amber-300 mb-3">Remaining Gaps</h3>
            <div class="space-y-2">
            @foreach($gaps as $gap)
                @php
                    $g    = (array) $gap;
                    $sev  = $g['severity'] ?? 'low';
                    $sevC = match($sev) {
                        'critical' => 'text-red-400', 'high' => 'text-orange-400',
                        'medium' => 'text-yellow-400', default => 'text-gray-400'
                    };
                @endphp
                <div class="border-b border-white/5 pb-2 text-xs">
                    <div class="flex gap-3">
                        <span class="font-mono text-cyan-400 w-14">{{ $g['gap_id'] }}</span>
                        <span class="font-semibold {{ $sevC }} w-16 uppercase">{{ $sev }}</span>
                        <span class="text-gray-300">{{ $g['description'] }}</span>
                    </div>
                    @if(!empty($g['resolution_path']))
                        <div class="ml-32 text-gray-500 mt-1">→ {{ $g['resolution_path'] }}</div>
                    @endif
                </div>
            @endforeach
            </div>
        </div>

        <div class="text-xs text-gray-600 text-center pt-2">
            Advisory-only snapshot. freeze_approved = false. Frozen at: {{ $summary['frozen_at'] ?? 'N/A' }}
        </div>

        @endif
    </div>
</x-app-layout>
