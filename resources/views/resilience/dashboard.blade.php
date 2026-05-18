<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">
            Resilience Validation Dashboard
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('success'))
            <div class="p-3 rounded bg-green-900/30 border border-green-500/40 text-green-300 text-sm">
                {{ session('success') }}
            </div>
            @endif

            {{-- Summary Stats --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                @foreach ([
                    ['label' => 'Total Runs', 'value' => $summary['total'], 'color' => 'cyan'],
                    ['label' => 'Passed',     'value' => $summary['passed'],'color' => 'green'],
                    ['label' => 'Failed',     'value' => $summary['failed'],'color' => 'red'],
                    ['label' => 'Error',      'value' => $summary['error'], 'color' => 'yellow'],
                ] as $stat)
                <div class="glass-card p-4">
                    <div class="text-2xl font-bold text-{{ $stat['color'] }}-300">{{ $stat['value'] }}</div>
                    <div class="text-xs text-cyan-400/70 mt-1">{{ $stat['label'] }}</div>
                </div>
                @endforeach
            </div>

            {{-- Scenario Status Grid --}}
            <div class="glass-card p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-cyan-100">Scenario Status</h3>
                    <form method="POST" action="{{ route('resilience.run') }}" class="flex gap-2">
                        @csrf
                        <select name="scenario_id" class="text-sm bg-cyan-900/30 border border-cyan-100/20 rounded px-2 py-1 text-cyan-200">
                            <option value="all">All Scenarios</option>
                            @foreach ($scenarios as $id => $meta)
                            <option value="{{ $id }}">{{ $meta['name'] }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="text-sm px-3 py-1 rounded bg-cyan-700/30 hover:bg-cyan-600/40 text-cyan-200 border border-cyan-100/20">
                            Run
                        </button>
                    </form>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-cyan-200">
                        <thead>
                            <tr class="border-b border-cyan-100/20 text-cyan-400 text-xs uppercase">
                                <th class="pb-2 text-left">Scenario</th>
                                <th class="pb-2 text-center">Type</th>
                                <th class="pb-2 text-center">Last Status</th>
                                <th class="pb-2 text-left">Description</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyan-100/10">
                            @foreach ($scenarios as $id => $meta)
                            <tr class="hover:bg-cyan-900/20">
                                <td class="py-2 font-mono text-xs">{{ $id }}</td>
                                <td class="py-2 text-center">
                                    <span class="px-2 py-0.5 rounded text-xs
                                        @if($meta['type'] === 'active') bg-blue-900/40 text-blue-300
                                        @else bg-cyan-900/40 text-cyan-300 @endif">
                                        {{ $meta['type'] }}
                                    </span>
                                </td>
                                <td class="py-2 text-center">
                                    @php $st = $scenarioStatus[$id] ?? 'never_run'; @endphp
                                    <span class="px-2 py-0.5 rounded text-xs font-mono
                                        @if($st === 'passed') bg-green-900/40 text-green-300
                                        @elseif($st === 'failed') bg-red-900/40 text-red-300
                                        @elseif($st === 'error') bg-yellow-900/40 text-yellow-300
                                        @else bg-gray-900/40 text-gray-400 @endif">
                                        {{ $st }}
                                    </span>
                                </td>
                                <td class="py-2 text-xs text-cyan-300/60">{{ Str::limit($meta['description'], 80) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Recent Runs --}}
            @if ($recentRuns->isNotEmpty())
            <div class="glass-card p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-cyan-100">Recent Runs</h3>
                    <a href="{{ route('resilience.history') }}" class="text-sm text-cyan-400 hover:text-cyan-200">View all →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-cyan-200">
                        <thead>
                            <tr class="border-b border-cyan-100/20 text-cyan-400 text-xs uppercase">
                                <th class="pb-2 text-left">Run ID</th>
                                <th class="pb-2 text-left">Scenario</th>
                                <th class="pb-2 text-center">Type</th>
                                <th class="pb-2 text-center">Status</th>
                                <th class="pb-2 text-center">Shadow Isolated</th>
                                <th class="pb-2 text-center">Replay Safe</th>
                                <th class="pb-2 text-right">Duration</th>
                                <th class="pb-2 text-right">Started</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyan-100/10">
                            @foreach ($recentRuns as $run)
                            <tr class="hover:bg-cyan-900/20">
                                <td class="py-2">
                                    <a href="{{ route('resilience.show', $run->run_id) }}" class="font-mono text-xs text-cyan-400 hover:text-cyan-200">
                                        {{ $run->run_id }}
                                    </a>
                                </td>
                                <td class="py-2 text-xs">{{ $run->scenario_name }}</td>
                                <td class="py-2 text-center">
                                    <span class="px-1.5 py-0.5 rounded text-xs font-mono
                                        @if($run->scenario_type === 'active') bg-blue-900/40 text-blue-300
                                        @else bg-cyan-900/40 text-cyan-300 @endif">
                                        {{ $run->scenario_type }}
                                    </span>
                                </td>
                                <td class="py-2 text-center">
                                    <span class="px-2 py-0.5 rounded text-xs font-mono
                                        @if($run->status === 'passed') bg-green-900/40 text-green-300
                                        @elseif($run->status === 'failed') bg-red-900/40 text-red-300
                                        @else bg-yellow-900/40 text-yellow-300 @endif">
                                        {{ $run->status }}
                                    </span>
                                </td>
                                <td class="py-2 text-center text-xs">
                                    {{ $run->endpoint_shadow_isolated ? '✓' : '✗' }}
                                </td>
                                <td class="py-2 text-center text-xs">
                                    {{ $run->replay_safe ? '✓' : '✗' }}
                                </td>
                                <td class="py-2 text-right font-mono text-xs text-cyan-400/70">
                                    {{ $run->durationSeconds() !== null ? round($run->durationSeconds(), 2) . 's' : '—' }}
                                </td>
                                <td class="py-2 text-right text-xs text-cyan-400/70 whitespace-nowrap">
                                    {{ $run->started_at?->format('m-d H:i:s') }}
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

        </div>
    </div>
</x-app-layout>
