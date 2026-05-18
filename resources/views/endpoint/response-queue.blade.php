<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Response Command Queue</h2>
                <p class="text-xs text-cyan-400/50 mt-1">Phase 1 — safe commands only, all require approval before dispatch</p>
            </div>
            <a href="{{ route('endpoint.agent.inventory') }}"
               class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">
                ← Agent Inventory
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('success'))
                <div class="glass-card p-4 border border-green-400/30 text-green-300 text-sm">{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="glass-card p-4 border border-red-400/30 text-red-300 text-sm">{{ $errors->first() }}</div>
            @endif

            {{-- Active / pending commands --}}
            <div class="glass-card p-6">
                <h3 class="text-sm font-semibold text-cyan-200 mb-4">Active Commands</h3>
                @if ($pending->isEmpty())
                    <p class="text-cyan-400/50 text-sm">No active commands.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-cyan-200/80">
                            <thead>
                                <tr class="text-left text-xs text-cyan-400/60 border-b border-cyan-200/10">
                                    <th class="pb-2 pr-4">Command ID</th>
                                    <th class="pb-2 pr-4">Agent</th>
                                    <th class="pb-2 pr-4">Type</th>
                                    <th class="pb-2 pr-4">Status</th>
                                    <th class="pb-2 pr-4">Created</th>
                                    <th class="pb-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($pending as $cmd)
                                    <tr class="border-b border-cyan-200/5 hover:bg-cyan-100/5">
                                        <td class="py-2 pr-4 font-mono text-xs">{{ $cmd->command_id }}</td>
                                        <td class="py-2 pr-4">{{ $cmd->agent?->hostname ?? $cmd->agent?->agent_id ?? '—' }}</td>
                                        <td class="py-2 pr-4 font-mono text-xs">{{ $cmd->command_type }}</td>
                                        <td class="py-2 pr-4">
                                            <span class="px-2 py-0.5 rounded text-xs font-medium
                                                @if($cmd->status === 'pending_approval') bg-yellow-900/40 text-yellow-300
                                                @elseif($cmd->status === 'approved') bg-green-900/40 text-green-300
                                                @elseif($cmd->status === 'dispatched') bg-blue-900/40 text-blue-300
                                                @else bg-cyan-900/40 text-cyan-300 @endif">
                                                {{ $cmd->status }}
                                            </span>
                                        </td>
                                        <td class="py-2 pr-4 text-xs text-cyan-400/60">{{ $cmd->created_at?->diffForHumans() }}</td>
                                        <td class="py-2">
                                            <a href="{{ route('endpoint.response.show', $cmd->command_id) }}"
                                               class="text-xs text-cyan-400 hover:text-cyan-200">View →</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">{{ $pending->links() }}</div>
                @endif
            </div>

            {{-- Recent completed / cancelled --}}
            <div class="glass-card p-6">
                <h3 class="text-sm font-semibold text-cyan-200 mb-4">Recent Completed</h3>
                @if ($recent->isEmpty())
                    <p class="text-cyan-400/50 text-sm">No completed commands yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-cyan-200/80">
                            <thead>
                                <tr class="text-left text-xs text-cyan-400/60 border-b border-cyan-200/10">
                                    <th class="pb-2 pr-4">Command ID</th>
                                    <th class="pb-2 pr-4">Agent</th>
                                    <th class="pb-2 pr-4">Type</th>
                                    <th class="pb-2 pr-4">Status</th>
                                    <th class="pb-2 pr-4">Completed</th>
                                    <th class="pb-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recent as $cmd)
                                    <tr class="border-b border-cyan-200/5 hover:bg-cyan-100/5">
                                        <td class="py-2 pr-4 font-mono text-xs">{{ $cmd->command_id }}</td>
                                        <td class="py-2 pr-4">{{ $cmd->agent?->hostname ?? $cmd->agent?->agent_id ?? '—' }}</td>
                                        <td class="py-2 pr-4 font-mono text-xs">{{ $cmd->command_type }}</td>
                                        <td class="py-2 pr-4">
                                            <span class="px-2 py-0.5 rounded text-xs font-medium
                                                @if($cmd->status === 'completed') bg-green-900/40 text-green-300
                                                @elseif($cmd->status === 'failed') bg-red-900/40 text-red-300
                                                @else bg-gray-900/40 text-gray-400 @endif">
                                                {{ $cmd->status }}
                                            </span>
                                        </td>
                                        <td class="py-2 pr-4 text-xs text-cyan-400/60">{{ $cmd->completed_at?->diffForHumans() ?? $cmd->updated_at?->diffForHumans() }}</td>
                                        <td class="py-2">
                                            <a href="{{ route('endpoint.response.show', $cmd->command_id) }}"
                                               class="text-xs text-cyan-400 hover:text-cyan-200">View →</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
