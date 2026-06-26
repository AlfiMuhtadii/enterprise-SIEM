<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Stability Evidence Freeze v4</h2>
        <p class="text-xs text-amber-400/80 mt-1">ENTERPRISE-059 — Advisory-only. Phase range: {{ \App\Services\StabilityEvidenceFreezeV4Service::PHASE_RANGE }}. freeze_approved = false.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-4">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory:</strong> freeze_approved = false always. FREEZE_APPROVED is a documentation record, not a deployment gate.
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
                <div class="text-2xl font-bold text-cyan-400">{{ $summary['gates_passed'] ?? 0 }}/{{ $summary['total_gates'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Gates Passed</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-white">{{ $summary['total_phases'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Phases (E055–E058)</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-amber-400">{{ $summary['gap_count'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Remaining Gaps</div>
            </div>
        </div>
        {{-- Gate table --}}
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-300 mb-2">Freeze Gates (EV4-01 – EV4-16)</h3>
            <table class="w-full text-xs text-gray-300">
                <thead><tr class="border-b border-white/10 text-gray-400">
                    <th class="text-left py-2 pr-3">Gate</th><th class="text-left py-2 pr-3">Status</th><th class="text-left py-2">Evidence</th>
                </tr></thead>
                <tbody>
                @foreach($gates as $g)
                    @php $g = (array) $g; @endphp
                    <tr class="border-b border-white/5">
                        <td class="py-1 pr-3 font-mono text-xs">{{ $g['gate_id'] ?? '' }}</td>
                        <td class="py-1 pr-3 {{ ($g['passed'] ?? false) ? 'text-green-400' : 'text-red-400' }}">{{ ($g['passed'] ?? false) ? 'PASS' : 'FAIL' }}</td>
                        <td class="py-1 text-gray-400 text-xs">{{ $g['evidence'] ?? '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{-- Phase summaries --}}
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-300 mb-2">Phase Summaries</h3>
            <table class="w-full text-xs text-gray-300">
                <thead><tr class="border-b border-white/10 text-gray-400">
                    <th class="text-left py-2 pr-3">Phase</th><th class="text-left py-2 pr-3">Status</th><th class="text-left py-2">Summary</th>
                </tr></thead>
                <tbody>
                @foreach($phases as $p)
                    @php $p = (array) $p; @endphp
                    <tr class="border-b border-white/5">
                        <td class="py-1 pr-3 text-cyan-300">{{ $p['phase_id'] ?? '' }}</td>
                        <td class="py-1 pr-3 {{ ($p['status'] ?? '') === 'COMPLETE' ? 'text-green-400' : 'text-yellow-400' }}">{{ $p['status'] ?? '' }}</td>
                        <td class="py-1 text-gray-400 text-xs">{{ $p['summary'] ?? '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{-- Claims --}}
        <div class="grid grid-cols-2 gap-4">
            <div class="glass-card p-4">
                <h3 class="text-sm font-semibold text-green-300 mb-2">Allowed Claims ({{ $claims->where('claim_type','allowed')->count() }})</h3>
                <ul class="space-y-1">
                @foreach($claims->where('claim_type','allowed') as $c)
                    @php $c = (array) $c; @endphp
                    <li class="text-xs text-gray-300">✓ {{ $c['claim_text'] ?? '' }}</li>
                @endforeach
                </ul>
            </div>
            <div class="glass-card p-4">
                <h3 class="text-sm font-semibold text-red-300 mb-2">Forbidden Claims ({{ $claims->where('claim_type','forbidden')->count() }})</h3>
                <ul class="space-y-1">
                @foreach($claims->where('claim_type','forbidden') as $c)
                    @php $c = (array) $c; @endphp
                    <li class="text-xs text-gray-300">✗ {{ $c['claim_text'] ?? '' }}</li>
                @endforeach
                </ul>
            </div>
        </div>
        {{-- Gap registry --}}
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-amber-300 mb-2">Gap Registry ({{ $gaps->count() }} remaining)</h3>
            <table class="w-full text-xs text-gray-300">
                <thead><tr class="border-b border-white/10 text-gray-400">
                    <th class="text-left py-2 pr-3">Gap</th><th class="text-left py-2 pr-3">Severity</th><th class="text-left py-2">Description</th>
                </tr></thead>
                <tbody>
                @foreach($gaps as $g)
                    @php $g = (array) $g; $sev = $g['severity'] ?? 'LOW'; @endphp
                    <tr class="border-b border-white/5">
                        <td class="py-1 pr-3 font-mono text-xs text-amber-300">{{ $g['gap_id'] ?? '' }}</td>
                        <td class="py-1 pr-3 text-xs {{ $sev === 'CRITICAL' ? 'text-red-400' : ($sev === 'HIGH' ? 'text-orange-400' : ($sev === 'MEDIUM' ? 'text-yellow-400' : 'text-gray-400')) }}">{{ $sev }}</td>
                        <td class="py-1 text-gray-400 text-xs">{{ $g['description'] ?? '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
