<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Long-Lived Processes</h2>
                <p class="text-xs text-amber-400/80 mt-1 font-medium">Shadow-only endpoint visibility. No active containment.</p>
                <p class="text-xs text-cyan-400/50 mt-0.5">{{ $agent->hostname ?? $agent->agent_id }} — processes alive &gt; 1 hour</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('endpoint.behavioral.process-tree', $agent->agent_id) }}"
                   class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">Process Tree</a>
                <a href="{{ route('endpoint.behavioral.activity', $agent->agent_id) }}"
                   class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">← Timeline</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="glass-card p-6">
                <h3 class="text-sm font-semibold text-cyan-200 mb-4">Long-Lived Process Table</h3>
                @if (empty($processes))
                    <p class="text-cyan-400/50 text-sm">No long-lived processes detected in the latest snapshot (threshold: 1 hour).</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-cyan-200/80">
                            <thead>
                                <tr class="text-left text-xs text-cyan-400/60 border-b border-cyan-200/10">
                                    <th class="pb-2 pr-4">PID</th>
                                    <th class="pb-2 pr-4">Process</th>
                                    <th class="pb-2 pr-4">User</th>
                                    <th class="pb-2 pr-4">Duration</th>
                                    <th class="pb-2 pr-4">First Seen</th>
                                    <th class="pb-2 pr-4">Last Seen</th>
                                    <th class="pb-2 pr-4">Shell</th>
                                    <th class="pb-2">Command Line</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($processes as $proc)
                                <tr class="border-b border-cyan-200/5 hover:bg-cyan-100/5 {{ $proc['is_shell'] ? 'bg-yellow-900/10' : '' }}">
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $proc['pid'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs font-medium
                                        {{ $proc['is_shell'] ? 'text-yellow-300' : 'text-cyan-100' }}">{{ $proc['process_name'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-xs">{{ $proc['user'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs text-orange-300">
                                        {{ gmdate('H:i:s', $proc['duration_seconds'] ?? 0) }}
                                        <span class="text-cyan-400/40 ml-1">({{ number_format(($proc['duration_seconds'] ?? 0) / 3600, 1) }}h)</span>
                                    </td>
                                    <td class="py-2 pr-4 text-xs text-cyan-400/60">{{ $proc['first_seen_at'] ? \Carbon\Carbon::parse($proc['first_seen_at'])->diffForHumans() : '—' }}</td>
                                    <td class="py-2 pr-4 text-xs text-cyan-400/60">{{ $proc['last_seen_at'] ? \Carbon\Carbon::parse($proc['last_seen_at'])->diffForHumans() : '—' }}</td>
                                    <td class="py-2 pr-4 text-xs">
                                        @if ($proc['is_shell'])
                                            <span class="px-2 py-0.5 rounded bg-yellow-900/40 text-yellow-300 text-xs">yes</span>
                                        @endif
                                    </td>
                                    <td class="py-2 text-xs text-cyan-400/50 max-w-xs truncate">{{ Str::limit($proc['command_line'] ?? '', 60) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="glass-card p-4 border border-amber-400/20">
                <p class="text-xs text-amber-300/80">
                    Shadow-only endpoint visibility. Duration is tracked from agent first-observation (not system boot time).
                    Long-lived interactive shells are flagged as potential persistent reverse shells for analyst review.
                    No process termination is implemented.
                </p>
            </div>

        </div>
    </div>
</x-app-layout>
