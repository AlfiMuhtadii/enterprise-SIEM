<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Host Activity Timeline</h2>
                <p class="text-xs text-amber-400/80 mt-1 font-medium">Shadow-only endpoint visibility. No active containment.</p>
                <p class="text-xs text-cyan-400/50 mt-0.5">{{ $agent->hostname ?? $agent->agent_id }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('endpoint.behavioral.process-tree', $agent->agent_id) }}"
                   class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">Process Tree</a>
                <a href="{{ route('endpoint.agent.detail', $agent->agent_id) }}"
                   class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">← Agent Detail</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="glass-card p-6">
                <h3 class="text-sm font-semibold text-cyan-200 mb-4">Behavioral Snapshots (most recent first)</h3>
                @if (empty($timeline))
                    <p class="text-cyan-400/50 text-sm">No behavioral snapshots collected yet. Ensure the agent is running with behavioral snapshot collection enabled.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-cyan-200/80">
                            <thead>
                                <tr class="text-left text-xs text-cyan-400/60 border-b border-cyan-200/10">
                                    <th class="pb-2 pr-4">Snapshot ID</th>
                                    <th class="pb-2 pr-4">Collected At</th>
                                    <th class="pb-2 pr-4">Processes</th>
                                    <th class="pb-2 pr-4">Shells</th>
                                    <th class="pb-2 pr-4">Long-lived</th>
                                    <th class="pb-2 pr-4">Suspicious</th>
                                    <th class="pb-2 pr-4">Trace ID</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($timeline as $snap)
                                <tr class="border-b border-cyan-200/5 hover:bg-cyan-100/5">
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $snap['snapshot_id'] }}</td>
                                    <td class="py-2 pr-4 text-xs">{{ $snap['collected_at'] }}</td>
                                    <td class="py-2 pr-4">{{ $snap['process_count'] }}</td>
                                    <td class="py-2 pr-4 {{ $snap['shell_count'] > 0 ? 'text-yellow-300' : '' }}">{{ $snap['shell_count'] }}</td>
                                    <td class="py-2 pr-4 {{ $snap['long_lived_count'] > 0 ? 'text-orange-300' : '' }}">{{ $snap['long_lived_count'] }}</td>
                                    <td class="py-2 pr-4 {{ $snap['suspicious_count'] > 0 ? 'text-red-300' : '' }}">{{ $snap['suspicious_count'] }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs text-cyan-400/50 truncate max-w-xs">{{ Str::limit($snap['trace_id'] ?? '', 20) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="glass-card p-4 border border-amber-400/20">
                <p class="text-xs text-amber-300/80">
                    Shadow-only endpoint visibility. Process data is for investigation and detection validation only.
                    No active containment, isolation, or process termination is implemented or possible from this interface.
                </p>
            </div>

        </div>
    </div>
</x-app-layout>
