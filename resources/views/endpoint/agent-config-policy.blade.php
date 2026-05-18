<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Config Policy</h2>
                <p class="text-xs text-cyan-400/50 mt-1">{{ $agent->hostname ?? $agent->host_id }} — {{ $agent->agent_id }}</p>
            </div>
            <a href="{{ route('endpoint.agent.detail', $agent->agent_id) }}"
               class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">
                ← Detail
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('success'))
            <div class="p-3 rounded bg-green-900/30 border border-green-500/40 text-green-300 text-sm">
                {{ session('success') }}
            </div>
            @endif

            <form method="POST" action="{{ route('endpoint.agent.config.update', $agent->agent_id) }}">
                @csrf
                {{-- Telemetry toggles --}}
                <div class="glass-card p-6 space-y-4">
                    <h3 class="text-lg font-semibold text-cyan-100">Telemetry Toggles</h3>
                    @php $tel = $config['telemetry'] ?? []; @endphp
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        @foreach ([
                            ['name' => 'telemetry_process',         'label' => 'Process'],
                            ['name' => 'telemetry_network',         'label' => 'Network'],
                            ['name' => 'telemetry_dns',             'label' => 'DNS'],
                            ['name' => 'telemetry_file',            'label' => 'File Write'],
                            ['name' => 'telemetry_scheduled_tasks', 'label' => 'Scheduled Tasks'],
                            ['name' => 'telemetry_services',        'label' => 'Services'],
                        ] as $toggle)
                        @php
                            $key    = str_replace('telemetry_', '', $toggle['name']);
                            $active = $tel[$key] ?? false;
                        @endphp
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" name="{{ $toggle['name'] }}" value="1"
                                   @checked($active)
                                   class="w-4 h-4 rounded bg-cyan-900 border-cyan-400/30">
                            <span class="text-sm text-cyan-200">{{ $toggle['label'] }}</span>
                        </label>
                        @endforeach
                    </div>
                </div>

                {{-- Intervals & Limits --}}
                <div class="glass-card p-6 mt-4 space-y-4">
                    <h3 class="text-lg font-semibold text-cyan-100">Intervals & Buffer Limits</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        @foreach ([
                            ['name' => 'batch_interval_seconds',    'label' => 'Batch Interval (s)',    'default' => 30,   'min' => 5,   'max' => 300],
                            ['name' => 'heartbeat_interval_seconds','label' => 'Heartbeat Interval (s)','default' => 60,   'min' => 10,  'max' => 600],
                            ['name' => 'buffer_size',               'label' => 'Buffer Size',           'default' => 1000, 'min' => 100, 'max' => 10000],
                            ['name' => 'max_buffer_size',           'label' => 'Max Buffer Size',       'default' => 5000, 'min' => 500, 'max' => 50000],
                            ['name' => 'disk_pressure_threshold_mb','label' => 'Disk Guard (MB)',       'default' => 100,  'min' => 10,  'max' => 1000],
                        ] as $field)
                        <div>
                            <label class="block text-xs text-cyan-400/70 mb-1">{{ $field['label'] }}</label>
                            <input type="number"
                                   name="{{ $field['name'] }}"
                                   value="{{ $config[$field['name']] ?? $field['default'] }}"
                                   min="{{ $field['min'] }}" max="{{ $field['max'] }}"
                                   class="w-full rounded bg-cyan-900/30 border border-cyan-100/20 px-2 py-1.5 text-sm text-cyan-100 focus:outline-none focus:border-cyan-400/50">
                        </div>
                        @endforeach
                    </div>
                </div>

                {{-- Watch paths --}}
                <div class="glass-card p-6 mt-4">
                    <h3 class="text-lg font-semibold text-cyan-100 mb-3">Allowed Watch Paths</h3>
                    <p class="text-xs text-cyan-400/50 mb-2">One path per line. File telemetry only scans these directories.</p>
                    <textarea name="allowed_watch_paths" rows="4"
                              class="w-full rounded bg-cyan-900/30 border border-cyan-100/20 px-3 py-2 text-sm font-mono text-cyan-100 focus:outline-none focus:border-cyan-400/50">{{ implode("\n", $config['allowed_watch_paths'] ?? []) }}</textarea>
                </div>

                <div class="mt-4 flex justify-end">
                    <button type="submit"
                            class="px-4 py-2 rounded bg-cyan-700/40 hover:bg-cyan-600/50 border border-cyan-400/30 text-sm text-cyan-200 transition">
                        Save Config Policy
                    </button>
                </div>
            </form>

            {{-- Config history --}}
            @if ($configHistory->isNotEmpty())
            <div class="glass-card p-6">
                <h3 class="text-lg font-semibold text-cyan-100 mb-4">Policy History</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-cyan-200">
                        <thead>
                            <tr class="border-b border-cyan-100/20 text-cyan-400 text-xs uppercase">
                                <th class="pb-2 text-left">Applied At</th>
                                <th class="pb-2 text-center">Version</th>
                                <th class="pb-2 text-center">Batch (s)</th>
                                <th class="pb-2 text-center">Buffer</th>
                                <th class="pb-2 text-center">Process</th>
                                <th class="pb-2 text-center">Network</th>
                                <th class="pb-2 text-center">DNS</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyan-100/10">
                            @foreach ($configHistory as $hist)
                            @php $c = $hist->config ?? []; $t = $c['telemetry'] ?? []; @endphp
                            <tr class="hover:bg-cyan-900/20">
                                <td class="py-2 text-xs text-cyan-400/70">{{ $hist->applied_at?->format('Y-m-d H:i') }}</td>
                                <td class="py-2 text-center font-mono text-xs">{{ $c['policy_version'] ?? '—' }}</td>
                                <td class="py-2 text-center text-xs">{{ $c['batch_interval_seconds'] ?? '—' }}</td>
                                <td class="py-2 text-center text-xs">{{ $c['buffer_size'] ?? '—' }}/{{ $c['max_buffer_size'] ?? '—' }}</td>
                                <td class="py-2 text-center text-xs {{ ($t['process'] ?? false) ? 'text-green-400' : 'text-red-400/60' }}">{{ ($t['process'] ?? false) ? '●' : '○' }}</td>
                                <td class="py-2 text-center text-xs {{ ($t['network'] ?? false) ? 'text-green-400' : 'text-red-400/60' }}">{{ ($t['network'] ?? false) ? '●' : '○' }}</td>
                                <td class="py-2 text-center text-xs {{ ($t['dns'] ?? false) ? 'text-green-400' : 'text-red-400/60' }}">{{ ($t['dns'] ?? false) ? '●' : '○' }}</td>
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
