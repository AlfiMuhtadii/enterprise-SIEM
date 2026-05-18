<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Process Tree View</h2>
                <p class="text-xs text-amber-400/80 mt-1 font-medium">Shadow-only endpoint visibility. No active containment.</p>
                <p class="text-xs text-cyan-400/50 mt-0.5">{{ $agent->hostname ?? $agent->agent_id }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('endpoint.behavioral.long-lived', $agent->agent_id) }}"
                   class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">Long-Lived</a>
                <a href="{{ route('endpoint.behavioral.activity', $agent->agent_id) }}"
                   class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">← Timeline</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="glass-card p-6">
                <h3 class="text-sm font-semibold text-cyan-200 mb-4">Process Inventory (latest snapshot)</h3>
                @if (empty($processes))
                    <p class="text-cyan-400/50 text-sm">No process data available. The agent has not submitted a behavioral snapshot yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-cyan-200/80">
                            <thead>
                                <tr class="text-left text-xs text-cyan-400/60 border-b border-cyan-200/10">
                                    <th class="pb-2 pr-3">PID</th>
                                    <th class="pb-2 pr-3">PPID</th>
                                    <th class="pb-2 pr-3">Process</th>
                                    <th class="pb-2 pr-3">Parent</th>
                                    <th class="pb-2 pr-3">User</th>
                                    <th class="pb-2 pr-3">Duration</th>
                                    <th class="pb-2 pr-3">Flags</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($processes as $proc)
                                <tr class="border-b border-cyan-200/5 hover:bg-cyan-100/5
                                    {{ $proc['is_suspicious'] ? 'bg-red-900/10' : ($proc['is_shell'] ? 'bg-yellow-900/10' : '') }}">
                                    <td class="py-1.5 pr-3 font-mono text-xs">{{ $proc['pid'] ?? '—' }}</td>
                                    <td class="py-1.5 pr-3 font-mono text-xs text-cyan-400/60">{{ $proc['ppid'] ?? '—' }}</td>
                                    <td class="py-1.5 pr-3 font-mono text-xs font-medium
                                        {{ $proc['is_shell'] ? 'text-yellow-300' : 'text-cyan-100' }}">{{ $proc['process_name'] ?? '—' }}</td>
                                    <td class="py-1.5 pr-3 font-mono text-xs text-cyan-400/70">{{ $proc['parent_process_name'] ?? '—' }}</td>
                                    <td class="py-1.5 pr-3 text-xs">{{ $proc['user'] ?? '—' }}</td>
                                    <td class="py-1.5 pr-3 text-xs {{ $proc['is_long_lived'] ? 'text-orange-300' : '' }}">
                                        @if (($proc['duration_seconds'] ?? 0) > 0)
                                            {{ gmdate('H:i:s', $proc['duration_seconds']) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="py-1.5 pr-3 text-xs space-x-1">
                                        @if ($proc['is_shell']) <span class="px-1 py-0.5 rounded bg-yellow-900/40 text-yellow-300 text-xs">shell</span> @endif
                                        @if ($proc['is_long_lived']) <span class="px-1 py-0.5 rounded bg-orange-900/40 text-orange-300 text-xs">long-lived</span> @endif
                                        @if ($proc['is_suspicious']) <span class="px-1 py-0.5 rounded bg-red-900/40 text-red-300 text-xs">suspicious</span> @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs text-cyan-400/40 mt-3">Showing {{ count($processes) }} processes from latest snapshot.</p>
                @endif
            </div>

            <div class="glass-card p-4 border border-amber-400/20">
                <p class="text-xs text-amber-300/80">
                    Shadow-only endpoint visibility. Process ancestry data is for investigation only.
                    No process termination, injection, or modification is implemented.
                    Parent-child relationships are approximate (derived from /proc on Linux).
                </p>
            </div>

        </div>
    </div>
</x-app-layout>
