<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">
                    Command: {{ $command->command_id }}
                </h2>
                <p class="text-xs text-cyan-400/50 mt-1 font-mono">{{ $command->command_type }} — {{ $command->status }}</p>
            </div>
            <a href="{{ route('endpoint.response.queue') }}"
               class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">
                ← Response Queue
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

            {{-- Command Summary --}}
            <div class="glass-card p-6">
                <h3 class="text-sm font-semibold text-cyan-200 mb-4">Command Details</h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                    <div>
                        <div class="text-xs text-cyan-400/60 mb-1">Command ID</div>
                        <div class="font-mono text-cyan-100">{{ $command->command_id }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-cyan-400/60 mb-1">Type</div>
                        <div class="font-mono text-cyan-100">{{ $command->command_type }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-cyan-400/60 mb-1">Status</div>
                        <div>
                            <span class="px-2 py-0.5 rounded text-xs font-medium
                                @if($command->status === 'pending_approval') bg-yellow-900/40 text-yellow-300
                                @elseif($command->status === 'approved') bg-green-900/40 text-green-300
                                @elseif($command->status === 'dispatched') bg-blue-900/40 text-blue-300
                                @elseif($command->status === 'completed') bg-green-900/40 text-green-300
                                @elseif($command->status === 'failed') bg-red-900/40 text-red-300
                                @elseif($command->status === 'cancelled') bg-gray-900/40 text-gray-400
                                @else bg-cyan-900/40 text-cyan-300 @endif">
                                {{ $command->status }}
                            </span>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-cyan-400/60 mb-1">Agent</div>
                        <div class="text-cyan-100">{{ $command->agent?->hostname ?? $command->agent?->agent_id ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-cyan-400/60 mb-1">Created By</div>
                        <div class="text-cyan-100">{{ $command->createdBy?->name ?? 'system' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-cyan-400/60 mb-1">Created At</div>
                        <div class="text-cyan-100 text-xs">{{ $command->created_at?->toIso8601String() }}</div>
                    </div>
                    @if ($command->approved_by)
                    <div>
                        <div class="text-xs text-cyan-400/60 mb-1">Approved By</div>
                        <div class="text-cyan-100">{{ $command->approvedBy?->name ?? 'unknown' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-cyan-400/60 mb-1">Approved At</div>
                        <div class="text-cyan-100 text-xs">{{ $command->approved_at?->toIso8601String() }}</div>
                    </div>
                    @endif
                    <div>
                        <div class="text-xs text-cyan-400/60 mb-1">Trace ID</div>
                        <div class="font-mono text-cyan-400/70 text-xs truncate">{{ $command->trace_id }}</div>
                    </div>
                </div>

                @if ($command->parameters)
                <div class="mt-4">
                    <div class="text-xs text-cyan-400/60 mb-1">Parameters</div>
                    <pre class="text-xs bg-black/30 rounded p-2 text-cyan-300/80 overflow-x-auto">{{ json_encode($command->parameters, JSON_PRETTY_PRINT) }}</pre>
                </div>
                @endif

                @if ($command->result)
                <div class="mt-4">
                    <div class="text-xs text-cyan-400/60 mb-1">Result</div>
                    <pre class="text-xs bg-black/30 rounded p-2 text-green-300/80 overflow-x-auto">{{ json_encode($command->result, JSON_PRETTY_PRINT) }}</pre>
                </div>
                @endif

                @if ($command->error_message)
                <div class="mt-4">
                    <div class="text-xs text-cyan-400/60 mb-1">Error</div>
                    <div class="text-xs text-red-300/80 bg-black/30 rounded p-2">{{ $command->error_message }}</div>
                </div>
                @endif
            </div>

            {{-- Approval Panel --}}
            @if (!$command->isTerminal())
            <div class="glass-card p-6">
                <h3 class="text-sm font-semibold text-cyan-200 mb-4">Actions</h3>
                <div class="flex flex-wrap gap-3">

                    @if ($command->status === 'draft')
                    <form method="POST" action="{{ route('endpoint.response.submit', $command->command_id) }}">
                        @csrf
                        <button type="submit"
                            class="px-4 py-2 text-sm rounded bg-yellow-700/40 text-yellow-200 hover:bg-yellow-700/60 border border-yellow-400/30">
                            Submit for Approval
                        </button>
                    </form>
                    @endif

                    @if ($command->status === 'pending_approval')
                    <form method="POST" action="{{ route('endpoint.response.approve', $command->command_id) }}" class="flex gap-2 items-center">
                        @csrf
                        <input type="text" name="note" placeholder="Approval note (optional)"
                            class="text-sm px-3 py-2 rounded bg-black/30 border border-cyan-200/20 text-cyan-200 placeholder-cyan-400/40 w-48">
                        <button type="submit"
                            class="px-4 py-2 text-sm rounded bg-green-700/40 text-green-200 hover:bg-green-700/60 border border-green-400/30">
                            Approve
                        </button>
                    </form>
                    <form method="POST" action="{{ route('endpoint.response.reject', $command->command_id) }}" class="flex gap-2 items-center">
                        @csrf
                        <input type="text" name="reason" placeholder="Rejection reason"
                            class="text-sm px-3 py-2 rounded bg-black/30 border border-cyan-200/20 text-cyan-200 placeholder-cyan-400/40 w-48">
                        <button type="submit"
                            class="px-4 py-2 text-sm rounded bg-red-700/40 text-red-200 hover:bg-red-700/60 border border-red-400/30">
                            Reject
                        </button>
                    </form>
                    @endif

                    @if ($command->status === 'approved')
                    <form method="POST" action="{{ route('endpoint.response.dispatch', $command->command_id) }}">
                        @csrf
                        <button type="submit"
                            class="px-4 py-2 text-sm rounded bg-blue-700/40 text-blue-200 hover:bg-blue-700/60 border border-blue-400/30">
                            Dispatch to Agent
                        </button>
                    </form>
                    @endif

                    @if (in_array($command->status, ['draft', 'pending_approval', 'approved']))
                    <form method="POST" action="{{ route('endpoint.response.cancel', $command->command_id) }}">
                        @csrf
                        <button type="submit"
                            class="px-4 py-2 text-sm rounded bg-gray-700/40 text-gray-300 hover:bg-gray-700/60 border border-gray-400/30">
                            Cancel
                        </button>
                    </form>
                    @endif

                </div>
            </div>
            @endif

            {{-- Audit Trail (append-only events) --}}
            <div class="glass-card p-6">
                <h3 class="text-sm font-semibold text-cyan-200 mb-4">Audit Trail</h3>
                @if ($command->events->isEmpty())
                    <p class="text-cyan-400/50 text-sm">No events recorded.</p>
                @else
                    <div class="space-y-2">
                        @foreach ($command->events as $event)
                        <div class="flex items-start gap-4 text-xs border-b border-cyan-200/5 pb-2">
                            <div class="text-cyan-400/50 w-36 shrink-0">{{ $event->occurred_at?->toIso8601String() }}</div>
                            <div class="font-mono text-cyan-300 w-36 shrink-0">{{ $event->event_type }}</div>
                            <div class="text-cyan-400/70">
                                @if ($event->from_state)
                                    <span class="text-cyan-400/50">{{ $event->from_state }}</span>
                                    <span class="text-cyan-400/30 mx-1">→</span>
                                @endif
                                <span class="text-cyan-200">{{ $event->to_state }}</span>
                                @if ($event->actor)
                                    <span class="ml-2 text-cyan-400/40">by {{ $event->actor }}</span>
                                @endif
                                @if ($event->details)
                                    <span class="ml-2 text-cyan-400/40">{{ json_encode($event->details) }}</span>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
