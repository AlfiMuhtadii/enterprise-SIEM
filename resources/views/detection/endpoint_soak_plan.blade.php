<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Endpoint Shadow Domain Soak Plan</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">ENTERPRISE-048 — Advisory-only. plan_approved = false always. Run <code>php artisan endpoint:generate-soak-plan</code> to refresh.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Gate Enforcement:</strong> Tier 1 (soak_ready) rules require a domain-specific 6h endpoint soak PASS before promotion is possible.
            This view is read-only — no rules are promoted here.
            Next steps: (1) enroll endpoints, (2) validate advisory findings stability, (3) schedule 6h endpoint soak, (4) review ACTIVE_ALLOWLIST.
        </div>

        @if(empty($summary['plan_run_id'] ?? null) && ($summary['total_rules'] ?? 0) === 0)
            <div class="glass-card p-6 text-center text-gray-500">
                No soak plan generated yet. Run <code class="text-cyan-400">php artisan endpoint:generate-soak-plan</code> to create one.
            </div>
        @else

        {{-- Tier summary cards --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-gray-300">{{ $summary['total_rules'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Total Endpoint Shadow</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-cyan-400">{{ $summary['tier_1_count'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Tier 1 Soak Ready</div>
                <div class="text-xs text-amber-400/70 mt-1">&ge;{{ $summary['tier_1_threshold'] ?? 0.72 }}</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-yellow-400">{{ $summary['tier_2_count'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Tier 2 Evidence</div>
                <div class="text-xs text-gray-500 mt-1">&ge;{{ $summary['tier_2_threshold'] ?? 0.60 }}</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-orange-400">{{ $summary['tier_3_count'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Tier 3 Needs Tuning</div>
            </div>
        </div>

        {{-- Gate checks --}}
        @if($gates->isNotEmpty())
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-gray-300 mb-3">Prerequisite Gates</h3>
            <div class="space-y-2 text-xs">
                @foreach($gates as $gate)
                @php $gObj = (object)$gate; @endphp
                <div class="flex items-start gap-3">
                    <span class="{{ ($gObj->passed ?? false) ? 'text-green-400' : 'text-yellow-400' }} font-mono w-12 shrink-0">
                        {{ ($gObj->passed ?? false) ? 'PASS' : 'WARN' }}
                    </span>
                    <span class="text-gray-400 font-mono w-20 shrink-0">{{ $gObj->gate_id ?? '' }}</span>
                    <span class="text-gray-300">{{ $gObj->gate_name ?? '' }}</span>
                    <span class="text-gray-500 ml-auto">{{ $gObj->detail ?? '' }}</span>
                </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Per-tier rule tables --}}
        @foreach([
            ['tier' => 'tier_1_soak_ready',          'label' => 'Tier 1 — Soak Ready (schedule 6h soak window 1)',      'color' => 'text-cyan-400',   'border' => 'border-cyan-400/30'],
            ['tier' => 'tier_2_evidence_collection',  'label' => 'Tier 2 — Evidence Collection (≥14 days)',              'color' => 'text-yellow-400', 'border' => 'border-yellow-400/30'],
            ['tier' => 'tier_3_needs_tuning',         'label' => 'Tier 3 — Needs Tuning',                                'color' => 'text-orange-400', 'border' => 'border-orange-400/30'],
        ] as $section)
        @php $sectionRules = $rules->where('tier', $section['tier']); @endphp
        @if($sectionRules->isNotEmpty())
        <div class="glass-card border {{ $section['border'] }} overflow-hidden">
            <div class="px-4 py-2 border-b {{ $section['border'] }}">
                <span class="{{ $section['color'] }} font-semibold text-sm">{{ $section['label'] }}</span>
                <span class="text-gray-500 text-xs ml-2">({{ $sectionRules->count() }} rules)</span>
            </div>
            <table class="w-full text-xs text-gray-300">
                <thead>
                    <tr class="border-b border-gray-700/50 text-gray-500 uppercase">
                        <th class="px-4 py-2 text-left">Rule ID</th>
                        <th class="px-4 py-2 text-right">Confidence</th>
                        <th class="px-4 py-2 text-center">FP Risk</th>
                        <th class="px-4 py-2 text-center">Soak Window</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sectionRules as $row)
                    @php $rObj = (object)$row; @endphp
                    <tr class="border-b border-gray-800/40 hover:bg-gray-800/20">
                        <td class="px-4 py-1.5 font-mono">{{ $rObj->rule_id ?? '' }}</td>
                        <td class="px-4 py-1.5 text-right font-mono">{{ number_format((float)($rObj->confidence ?? 0), 2) }}</td>
                        <td class="px-4 py-1.5 text-center">{{ $rObj->false_positive_risk ?? '' }}</td>
                        <td class="px-4 py-1.5 text-center text-gray-500">{{ $rObj->estimated_soak_window ?? '-' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
        @endforeach

        @endif
    </div>
</x-app-layout>
