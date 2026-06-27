<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Real Domain Soak Execution Plan</h2>
        <p class="text-xs text-amber-400/80 mt-1">ENTERPRISE-060 — Advisory-only. real_execution_gated = true. Run <code>php artisan soak:plan-review</code>.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-4">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory:</strong> Promotion remains BLOCKED for endpoint/network/threat-intel until real 6h soak PASS. Each phase requires explicit operator trigger. This plan is advisory-only.
        </div>
        {{-- Summary cards --}}
        <div class="grid grid-cols-4 gap-4">
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-white">{{ $plan['phases_total'] ?? 4 }}</div>
                <div class="text-xs text-gray-400 mt-1">Phases</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-cyan-400">{{ $plan['gates_passed'] ?? 0 }}/{{ $plan['total_gates'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Gates Passed</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold {{ ($plan['overall_readiness'] ?? '') === 'READY_TO_SCHEDULE' ? 'text-green-400' : 'text-yellow-400' }}">
                    {{ $plan['overall_readiness'] ?? 'NOT_RUN' }}
                </div>
                <div class="text-xs text-gray-400 mt-1">Overall Readiness</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-red-400">BLOCKED</div>
                <div class="text-xs text-gray-400 mt-1">Promotion Status</div>
            </div>
        </div>
        {{-- Phase cards --}}
        @if($phases->isEmpty())
            <div class="glass-card p-4 text-center text-gray-500 text-sm">
                No plan run yet. Run <code class="text-cyan-400">php artisan soak:plan-review --dry-run</code> to evaluate gates.
            </div>
        @else
        <div class="grid grid-cols-2 gap-4">
        @foreach($phases as $p)
            @php $p = (array) $p; $phaseGates = $gates->where('phase_number', $p['phase_number']); @endphp
            <div class="glass-card p-4 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-cyan-300">Phase {{ $p['phase_number'] }}: {{ $p['phase_name'] ?? '' }}</span>
                    <span class="text-xs px-2 py-0.5 rounded {{ ($p['readiness_status'] ?? '') === 'READY' ? 'bg-green-900/40 text-green-300' : (($p['readiness_status'] ?? '') === 'BLOCKED' ? 'bg-red-900/40 text-red-300' : 'bg-yellow-900/40 text-yellow-300') }}">
                        {{ $p['readiness_status'] ?? '' }}
                    </span>
                </div>
                <p class="text-xs text-gray-400">{{ $p['rule_scope'] ?? '' }}</p>
                <div class="space-y-1">
                @foreach($phaseGates as $g)
                    @php $g = (array) $g; @endphp
                    <div class="flex items-start gap-2 text-xs">
                        <span class="{{ ($g['status'] ?? '') === 'pass' ? 'text-green-400' : (($g['status'] ?? '') === 'fail' ? 'text-red-400' : 'text-yellow-400') }}">
                            {{ strtoupper($g['status'] ?? '') }}
                        </span>
                        <span class="font-mono text-gray-500">{{ $g['gate_id'] ?? '' }}</span>
                        <span class="text-gray-300">{{ $g['gate_name'] ?? '' }}</span>
                    </div>
                @endforeach
                </div>
            </div>
        @endforeach
        </div>
        @endif
        {{-- Phase definitions (always visible) --}}
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-300 mb-3">Phase Execution Guide</h3>
            <div class="space-y-3">
            @foreach($definitions as $num => $def)
                <div class="border-b border-white/5 pb-2">
                    <div class="text-xs font-semibold text-white">Phase {{ $num }}: {{ $def['name'] }}</div>
                    <div class="text-xs text-gray-400 mt-1">{{ $def['purpose'] }}</div>
                    <div class="text-xs text-cyan-400 font-mono mt-1">{{ $def['soak_command'] }}</div>
                </div>
            @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
