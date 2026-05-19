<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Active Response</h2>
                <p class="text-xs text-amber-400/80 mt-1 font-medium">Controlled manual response only. No autonomous containment.</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('active-response.approval-queue') }}" class="text-sm px-3 py-1.5 rounded bg-amber-700/30 border border-amber-400/30 text-amber-200 hover:bg-amber-700/50">
                    Approval Queue ({{ $pendingApprovals->count() }})
                </a>
                <a href="{{ route('active-response.rollback-center') }}" class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">Rollback Center</a>
                <a href="{{ route('active-response.audit-explorer') }}" class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">Audit</a>
            </div>
        </div>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        @if(session('success'))
            <div class="rounded border border-green-400/30 bg-green-900/20 text-green-300 px-4 py-2 text-sm">{{ session('success') }}</div>
        @endif

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3">
            <p class="text-sm text-amber-300"><strong>Phase 2 Notice:</strong> Active response is controlled, manually approved, and fully audited.
            All actions require explicit dual approval where applicable. Simulation is required before execution.
            No autonomous containment. No mass fanout. Single target per execution.</p>
        </div>

        {{-- Create new execution --}}
        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">New Response Execution</h3>
            <form method="POST" action="{{ route('active-response.store') }}" class="space-y-3">
                @csrf
                @if($errors->any())
                    <div class="text-red-400 text-xs">{{ $errors->first() }}</div>
                @endif
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="text-xs text-gray-400 block mb-1">Action Type</label>
                        <select name="action_type" class="w-full bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5">
                            @foreach(\App\Models\ResponseExecution::ALLOWED_ACTIONS as $action)
                                <option value="{{ $action }}">{{ str_replace('_', ' ', $action) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-gray-400 block mb-1">Target Entity Type</label>
                        <select name="target_entity_type" class="w-full bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5">
                            <option value="user">User</option><option value="host">Host</option>
                            <option value="ip">IP</option><option value="domain">Domain</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-gray-400 block mb-1">Target Entity Key</label>
                        <input type="text" name="target_entity_key" placeholder="user@domain.com / 10.0.0.1 / hostname"
                               class="w-full bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5" />
                    </div>
                </div>
                <div>
                    <label class="text-xs text-gray-400 block mb-1">Rationale (required)</label>
                    <input type="text" name="rationale" placeholder="Reason for this response action (min 10 chars)"
                           class="w-full bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5" />
                </div>
                <div class="flex gap-3 items-center">
                    <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/50 border border-cyan-400/30 text-cyan-200 text-sm hover:bg-cyan-700/70">
                        Create Execution
                    </button>
                    <span class="text-xs text-gray-500">Will be created in draft state. Requires submission and approval before execution.</span>
                </div>
            </form>
        </div>

        {{-- Status breakdown --}}
        @if($statusCounts)
        <div class="grid grid-cols-3 md:grid-cols-6 gap-2">
            @foreach($statusCounts as $status => $count)
            <div class="rounded-lg border border-cyan-400/15 bg-gray-900/30 p-2 text-center">
                <div class="text-lg font-bold text-cyan-300">{{ $count }}</div>
                <div class="text-xs text-gray-500">{{ str_replace('_', ' ', $status) }}</div>
            </div>
            @endforeach
        </div>
        @endif

        {{-- Recent executions --}}
        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Recent Executions</h3>
            @if($recentExecutions->isEmpty())
                <p class="text-sm text-gray-500">No executions yet.</p>
            @else
            <table class="w-full text-xs text-left text-gray-300">
                <thead class="text-gray-400 uppercase border-b border-gray-700">
                    <tr><th class="py-2 pr-3">ID</th><th class="py-2 pr-3">Action</th><th class="py-2 pr-3">Target</th><th class="py-2 pr-3">Status</th><th class="py-2 pr-3">Blast</th><th class="py-2">Created</th></tr>
                </thead>
                <tbody>
                    @foreach($recentExecutions as $e)
                    <tr class="border-b border-gray-800 hover:bg-gray-800/20">
                        <td class="py-1.5 pr-3"><a href="{{ route('active-response.show', $e->execution_id) }}" class="text-cyan-400 hover:underline font-mono">{{ $e->execution_id }}</a></td>
                        <td class="py-1.5 pr-3">{{ str_replace('_', ' ', $e->action_type) }}</td>
                        <td class="py-1.5 pr-3 font-mono text-xs">{{ $e->target_entity_key }}</td>
                        <td class="py-1.5 pr-3"><span class="px-1.5 py-0.5 rounded text-xs bg-gray-700 text-gray-300">{{ str_replace('_', ' ', $e->status) }}</span></td>
                        <td class="py-1.5 pr-3">{{ number_format($e->blast_radius_score * 100) }}%</td>
                        <td class="py-1.5 text-gray-500">{{ $e->created_at?->diffForHumans() }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </div>
    </div>
</x-app-layout>
