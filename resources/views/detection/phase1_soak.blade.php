<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            ENTERPRISE-061: Phase 1 Soak Evidence
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        {{-- Advisory banner --}}
        <div class="bg-yellow-50 border border-yellow-300 text-yellow-800 rounded p-3 mb-6 text-sm">
            <strong>NO_PROMOTION = true</strong> &mdash;
            Phase 1 records pre-soak gate evidence only. No run from this framework authorizes rule promotion.
            Real soak PASS via <code class="font-mono bg-yellow-100 px-1 rounded">run_xdr_correlation_soak_6h.ps1</code> is required before any promotion gate opens.
        </div>

        {{-- Score cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-sm font-semibold text-gray-700">Scope</div>
                <div class="text-base font-bold text-blue-700 mt-1">staged_active_empirical</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-sm font-semibold text-gray-700">Duration target</div>
                <div class="text-2xl font-bold text-gray-800 mt-1">30–60 min</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-sm font-semibold text-gray-700">Gates</div>
                <div class="text-2xl font-bold text-gray-800 mt-1">P1G-01..P1G-08</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-sm font-semibold text-gray-700">Latest decision</div>
                @if($latestRun)
                    @php
                        $dec = $latestRun['plan']['decision'];
                        $cls = $dec === 'PASS' ? 'text-green-600' : ($dec === 'WARN' ? 'text-yellow-600' : 'text-red-600');
                    @endphp
                    <div class="text-2xl font-bold {{ $cls }} mt-1">{{ $dec }}</div>
                @else
                    <div class="text-2xl font-bold text-gray-400 mt-1">NO RUN</div>
                @endif
            </div>
        </div>

        {{-- Gate table --}}
        <div class="bg-white rounded shadow mb-6">
            <div class="px-4 py-3 border-b font-semibold text-gray-700">Phase 1 Gates</div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-gray-600 font-medium">Gate ID</th>
                            <th class="px-4 py-2 text-left text-gray-600 font-medium">Name</th>
                            <th class="px-4 py-2 text-left text-gray-600 font-medium">Type</th>
                            @if($latestRun)
                            <th class="px-4 py-2 text-left text-gray-600 font-medium">Status</th>
                            <th class="px-4 py-2 text-left text-gray-600 font-medium">Evidence</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($definitions as $def)
                            @php
                                $gateResult = null;
                                if ($latestRun) {
                                    foreach ($latestRun['gates'] as $g) {
                                        if ($g['gate_id'] === $def['gate_id']) {
                                            $gateResult = $g;
                                            break;
                                        }
                                    }
                                }
                                $gateNum = (int) substr($def['gate_id'], 4);
                                $type    = $gateNum <= 3 ? 'structural' : ($gateNum <= 6 ? 'live-DB' : 'advisory');
                            @endphp
                            <tr class="border-t hover:bg-gray-50">
                                <td class="px-4 py-2 font-mono text-gray-700">{{ $def['gate_id'] }}</td>
                                <td class="px-4 py-2 text-gray-700">{{ $def['gate_name'] }}</td>
                                <td class="px-4 py-2">
                                    <span class="px-2 py-0.5 rounded text-xs font-semibold
                                        {{ $type === 'structural' ? 'bg-blue-50 text-blue-700' : ($type === 'live-DB' ? 'bg-purple-50 text-purple-700' : 'bg-gray-100 text-gray-500') }}">
                                        {{ $type }}
                                    </span>
                                </td>
                                @if($latestRun)
                                <td class="px-4 py-2">
                                    @if($gateResult)
                                        <span class="px-2 py-0.5 rounded text-xs font-semibold
                                            {{ $gateResult['status'] === 'pass' ? 'bg-green-100 text-green-700' : ($gateResult['status'] === 'warn' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">
                                            {{ strtoupper($gateResult['status']) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-gray-500 text-xs max-w-xs truncate">{{ $gateResult['evidence'] ?? '' }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Run summary or no-run prompt --}}
        @if($latestRun)
            <div class="bg-white rounded shadow p-4 text-sm text-gray-600">
                <strong>Latest run:</strong>
                Scope: <span class="font-mono">{{ $latestRun['plan']['scope'] }}</span> &mdash;
                Duration target: {{ $latestRun['plan']['duration_minutes'] }}min &mdash;
                Rules in scope: {{ $latestRun['plan']['rules_in_scope'] }} &mdash;
                Gates: {{ $latestRun['plan']['gates_passed'] }} pass /
                       {{ $latestRun['plan']['gates_warned'] }} warn /
                       {{ $latestRun['plan']['gates_failed'] }} fail &mdash;
                Decision: <strong>{{ $latestRun['plan']['decision'] }}</strong> &mdash;
                Dry-run: {{ $latestRun['plan']['is_dry_run'] ? 'yes' : 'no' }}
            </div>
        @else
            <div class="bg-gray-50 border border-gray-200 rounded p-4 text-sm text-gray-500">
                No evidence recorded yet. Run:
                <code class="font-mono bg-gray-100 px-1 rounded">php artisan soak:phase1-run --dry-run</code>
                to check gates without persisting, or
                <code class="font-mono bg-gray-100 px-1 rounded">php artisan soak:phase1-run --duration=30</code>
                to record live evidence.
            </div>
        @endif

        {{-- Decision criteria --}}
        <div class="bg-white rounded shadow mt-6 p-4 text-sm text-gray-600">
            <div class="font-semibold text-gray-700 mb-2">Decision criteria</div>
            <ul class="list-disc list-inside space-y-1">
                <li><strong class="text-red-600">FAIL</strong> — any gate returns fail (DLQ errors, wrong engine, registry mismatch)</li>
                <li><strong class="text-yellow-600">WARN</strong> — no fails, but some gates warn (advisory or not yet measured)</li>
                <li><strong class="text-green-600">PASS</strong> — all 8 gates pass (requires P1G-07/P1G-08 to be instrumented via soak script)</li>
            </ul>
            <div class="mt-2 text-xs text-gray-400">
                P1G-07 (latency) and P1G-08 (fallback) are always advisory until the real soak script completes and reports output.
            </div>
        </div>
    </div>
</x-app-layout>
