<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Stability Evidence Freeze v2</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">ENTERPRISE-049 — Advisory-only. Covers E045–E048. freeze_approved = false always. Run <code>php artisan stability:freeze-v2</code> to refresh.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Freeze:</strong> This snapshot captures stability evidence across ENTERPRISE-045 through ENTERPRISE-048.
            It is informational evidence for analyst review. No rules are promoted. freeze_approved requires human sign-off.
        </div>

        @if(($summary['total_gates'] ?? 0) === 0)
            <div class="glass-card p-6 text-center text-gray-500">
                No freeze run found. Run <code class="text-cyan-400">php artisan stability:freeze-v2</code> to generate.
            </div>
        @else

        {{-- Score cards --}}
        @php
            $stability = $summary['stability'] ?? 'UNKNOWN';
            $score = $summary['pass_score'] ?? 0;
            $stabColor = $stability === 'STABLE' ? 'text-green-400' : ($stability === 'UNSTABLE' ? 'text-red-400' : 'text-gray-500');
        @endphp
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold {{ $stabColor }}">{{ $stability }}</div>
                <div class="text-xs text-gray-400 mt-1">Stability</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-gray-300">{{ number_format($score * 100, 0) }}%</div>
                <div class="text-xs text-gray-400 mt-1">Pass Score</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-green-400">{{ $summary['gates_passed'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Gates Passed</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-yellow-400">{{ $summary['gates_warn'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Warn</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-red-400">{{ $summary['gates_failed'] ?? 0 }}</div>
                <div class="text-xs text-gray-400 mt-1">Failed</div>
            </div>
        </div>

        {{-- Gate table --}}
        @if($gates->isNotEmpty())
        <div class="glass-card overflow-hidden">
            <div class="px-4 py-2 border-b border-gray-700/50">
                <span class="text-sm font-semibold text-gray-300">Gate Evidence (EF-01 through EF-12)</span>
            </div>
            <table class="w-full text-xs text-gray-300">
                <thead>
                    <tr class="border-b border-gray-700/50 text-gray-500 uppercase">
                        <th class="px-4 py-2 text-left">Gate</th>
                        <th class="px-4 py-2 text-center">Status</th>
                        <th class="px-4 py-2 text-left">Name</th>
                        <th class="px-4 py-2 text-left">Evidence</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($gates as $gate)
                    @php
                        $gObj = (object)$gate;
                        $sc = match($gObj->status ?? '') {
                            'pass' => 'text-green-400', 'warn' => 'text-yellow-400', 'fail' => 'text-red-400', default => 'text-gray-500'
                        };
                    @endphp
                    <tr class="border-b border-gray-800/40 hover:bg-gray-800/20">
                        <td class="px-4 py-1.5 font-mono">{{ $gObj->gate_id ?? '' }}</td>
                        <td class="px-4 py-1.5 text-center {{ $sc }} font-medium">{{ strtoupper($gObj->status ?? '') }}</td>
                        <td class="px-4 py-1.5">{{ $gObj->gate_name ?? '' }}</td>
                        <td class="px-4 py-1.5 text-gray-500 font-mono">{{ $gObj->evidence ?? '' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        {{-- Phase summaries --}}
        @if($phases->isNotEmpty())
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-gray-300 mb-3">Phase Summaries</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                @foreach($phases as $phase)
                @php
                    $pObj = (object)$phase;
                    $metrics = is_string($pObj->metrics ?? '') ? json_decode($pObj->metrics, true) : ($pObj->metrics ?? []);
                @endphp
                <div class="rounded border border-gray-700/40 p-3 text-xs">
                    <div class="font-semibold text-cyan-400 font-mono mb-1">{{ $pObj->enterprise_id ?? '' }} — {{ $pObj->phase_name ?? '' }}</div>
                    @foreach($metrics ?? [] as $k => $v)
                    <div class="text-gray-400"><span class="text-gray-500">{{ $k }}:</span> {{ is_bool($v) ? ($v ? 'true' : 'false') : $v }}</div>
                    @endforeach
                </div>
                @endforeach
            </div>
        </div>
        @endif

        <div class="glass-card p-4 text-xs text-gray-500">
            freeze_approved = false (always) &bull; Advisory-only &bull; Frozen at: {{ $summary['frozen_at'] ?? '' }}
        </div>

        @endif
    </div>
</x-app-layout>
