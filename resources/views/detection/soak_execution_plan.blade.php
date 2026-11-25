<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Domain Soak Readiness</h2>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-4">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory diagnostics:</strong> No live soak or rule promotion is authorized by this plan.
        </div>
        {{-- Summary cards --}}
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-white">{{ $plan['phases_total'] ?? 4 }}</div>
                <div class="text-xs text-gray-400 mt-1">Phases</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-cyan-400">{{ $plan['gates_passed'] ?? 0 }}/{{ $plan['total_gates'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Gates Passed</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-base font-semibold break-words {{ ($plan['overall_readiness'] ?? '') === 'DIAGNOSTIC_READY' ? 'text-green-400' : 'text-yellow-400' }}">
                    {{ str_replace('_', ' ', $plan['overall_readiness'] ?? 'NOT_RUN') }}
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
                No readiness review recorded.
            </div>
        @else
        <div class="grid gap-4 md:grid-cols-2">
        @foreach($phases as $p)
            @php $p = (array) $p; $phaseGates = $gates->where('phase_number', $p['phase_number']); @endphp
            <div class="glass-card p-4 space-y-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xs font-semibold text-cyan-300">Phase {{ $p['phase_number'] }}: {{ $p['phase_name'] ?? '' }}</span>
                    <span class="text-xs px-2 py-0.5 rounded {{ ($p['readiness_status'] ?? '') === 'DIAGNOSTIC_READY' ? 'bg-green-900/40 text-green-300' : (($p['readiness_status'] ?? '') === 'BLOCKED' ? 'bg-red-900/40 text-red-300' : 'bg-yellow-900/40 text-yellow-300') }}">
                        {{ str_replace('_', ' ', $p['readiness_status'] ?? '') }}
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
            <h3 class="text-sm font-semibold text-cyan-300 mb-3">Phase Diagnostics</h3>
            <div class="space-y-3">
            @foreach($definitions as $num => $def)
                <div class="border-b border-white/5 pb-2">
                    <div class="text-xs font-semibold text-white">Phase {{ $num }}: {{ $def['name'] }}</div>
                    <div class="text-xs text-gray-400 mt-1">{{ $def['purpose'] }}</div>
                    <div class="text-xs text-cyan-400 font-mono break-words mt-1">{{ $def['diagnostic_command'] }}</div>
                </div>
            @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
