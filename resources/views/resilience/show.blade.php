<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">
            Resilience Run — {{ $run->run_id }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="flex items-center gap-3">
                <a href="{{ route('resilience.index') }}" class="text-sm text-cyan-400 hover:text-cyan-200">← Dashboard</a>
                <a href="{{ route('resilience.history') }}" class="text-sm text-cyan-400 hover:text-cyan-200">History</a>
            </div>

            {{-- Run Summary --}}
            <div class="glass-card p-6">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4">
                    <div>
                        <div class="text-xs text-cyan-400/70 mb-1">Status</div>
                        <span class="px-2 py-1 rounded text-sm font-mono
                            @if($run->status === 'passed') bg-green-900/40 text-green-300
                            @elseif($run->status === 'failed') bg-red-900/40 text-red-300
                            @else bg-yellow-900/40 text-yellow-300 @endif">
                            {{ strtoupper($run->status) }}
                        </span>
                    </div>
                    <div>
                        <div class="text-xs text-cyan-400/70 mb-1">Scenario Type</div>
                        <span class="px-2 py-1 rounded text-sm font-mono
                            @if($run->scenario_type === 'active') bg-blue-900/40 text-blue-300
                            @else bg-cyan-900/40 text-cyan-300 @endif">
                            {{ $run->scenario_type }}
                        </span>
                    </div>
                    <div>
                        <div class="text-xs text-cyan-400/70 mb-1">Duration</div>
                        <div class="text-sm font-mono text-cyan-100">
                            {{ $run->durationSeconds() !== null ? round($run->durationSeconds(), 3) . 's' : 'n/a' }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-cyan-400/70 mb-1">Started At</div>
                        <div class="text-sm text-cyan-100">{{ $run->started_at?->format('Y-m-d H:i:s') }}</div>
                    </div>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-4 pt-4 border-t border-cyan-100/10">
                    @foreach ([
                        ['label' => 'Recovery Validated', 'value' => $run->recovery_validated],
                        ['label' => 'Replay Safe',        'value' => $run->replay_safe],
                        ['label' => 'Trace Preserved',    'value' => $run->trace_preserved],
                        ['label' => 'Shadow Isolated',    'value' => $run->endpoint_shadow_isolated],
                        ['label' => 'No Duplicates',      'value' => $run->no_duplicates],
                    ] as $flag)
                    <div class="flex items-center gap-2">
                        <span class="{{ $flag['value'] ? 'text-green-400' : 'text-red-400' }} text-lg">
                            {{ $flag['value'] ? '✓' : '✗' }}
                        </span>
                        <span class="text-xs text-cyan-300">{{ $flag['label'] }}</span>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Findings --}}
            @if ($run->findings)
            <div class="glass-card p-6">
                <h3 class="text-lg font-semibold text-cyan-100 mb-4">Findings</h3>
                @foreach ($run->findings['checks'] ?? [] as $checkName => $check)
                <div class="mb-3 p-3 rounded border
                    @if(($check['status'] ?? 'pass') === 'pass') border-green-500/20 bg-green-900/10
                    @elseif(($check['status'] ?? 'pass') === 'fail') border-red-500/20 bg-red-900/10
                    @else border-cyan-500/20 bg-cyan-900/10 @endif">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="{{ ($check['status'] ?? 'pass') === 'pass' ? 'text-green-400' : 'text-red-400' }}">
                            {{ ($check['status'] ?? 'pass') === 'pass' ? '✓' : '✗' }}
                        </span>
                        <span class="font-mono text-xs text-cyan-200">{{ $checkName }}</span>
                        @if(isset($check['status']))
                        <span class="text-xs px-1.5 py-0.5 rounded font-mono
                            @if($check['status'] === 'pass') bg-green-900/40 text-green-300
                            @else bg-red-900/40 text-red-300 @endif">
                            {{ $check['status'] }}
                        </span>
                        @endif
                    </div>
                    @if(isset($check['note']))
                    <p class="text-xs text-cyan-400/70 ml-6">{{ $check['note'] }}</p>
                    @endif
                    @foreach (array_diff_key($check, ['status' => 1, 'note' => 1]) as $k => $v)
                    <div class="text-xs text-cyan-400/60 ml-6 font-mono">{{ $k }}: {{ is_array($v) ? json_encode($v) : $v }}</div>
                    @endforeach
                </div>
                @endforeach
            </div>
            @endif

            {{-- Metrics --}}
            @if ($run->metrics->isNotEmpty())
            <div class="glass-card p-6">
                <h3 class="text-lg font-semibold text-cyan-100 mb-4">Recovery Metrics</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-cyan-200">
                        <thead>
                            <tr class="border-b border-cyan-100/20 text-cyan-400 text-xs uppercase">
                                <th class="pb-2 text-left">Metric</th>
                                <th class="pb-2 text-right">Value</th>
                                <th class="pb-2 text-center">Unit</th>
                                <th class="pb-2 text-right">Threshold</th>
                                <th class="pb-2 text-center">OK</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyan-100/10">
                            @foreach ($run->metrics as $metric)
                            <tr class="hover:bg-cyan-900/20">
                                <td class="py-2 font-mono text-xs">{{ $metric->metric_name }}</td>
                                <td class="py-2 text-right font-mono text-xs">{{ number_format($metric->metric_value, 2) }}</td>
                                <td class="py-2 text-center text-xs text-cyan-400/70">{{ $metric->unit }}</td>
                                <td class="py-2 text-right font-mono text-xs text-cyan-400/70">
                                    {{ $metric->threshold !== null ? $metric->threshold : '—' }}
                                </td>
                                <td class="py-2 text-center text-xs {{ $metric->within_threshold ? 'text-green-400' : 'text-red-400' }}">
                                    {{ $metric->within_threshold ? '✓' : '✗' }}
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            {{-- Report Path --}}
            @if ($run->report_path)
            <div class="glass-card p-4">
                <p class="text-xs text-cyan-400/60">
                    Report written to: <code class="font-mono bg-cyan-900/30 px-1 rounded">{{ $run->report_path }}</code>
                </p>
            </div>
            @endif

        </div>
    </div>
</x-app-layout>
