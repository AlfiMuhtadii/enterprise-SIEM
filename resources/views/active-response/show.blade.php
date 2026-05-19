<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Execution: {{ $execution->execution_id }}</h2>
            <p class="text-xs text-amber-400/80 mt-1 font-medium">Controlled manual response only. No autonomous containment.</p>
        </div>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-5">
        @if(session('success'))
            <div class="rounded border border-green-400/30 bg-green-900/20 text-green-300 px-4 py-2 text-sm">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="rounded border border-red-400/30 bg-red-900/20 text-red-300 px-4 py-2 text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Execution is operator-driven only. No automated action is taken by the system.
        </div>

        {{-- Summary --}}
        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><span class="text-gray-400 text-xs">Action</span><div class="text-cyan-200 font-medium">{{ str_replace('_', ' ', $execution->action_type) }}</div></div>
            <div><span class="text-gray-400 text-xs">Target</span><div class="text-gray-300 font-mono text-xs">{{ $execution->target_entity_key }}</div></div>
            <div><span class="text-gray-400 text-xs">Status</span><div class="text-cyan-200 font-medium">{{ str_replace('_', ' ', $execution->status) }}</div></div>
            <div><span class="text-gray-400 text-xs">Blast Radius</span><div class="text-{{ $execution->blast_radius_score > 0.6 ? 'red' : 'gray' }}-300">{{ number_format($execution->blast_radius_score * 100) }}%</div></div>
        </div>

        {{-- Approval status --}}
        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-2">Approval</h3>
            <div class="flex gap-6 text-xs text-gray-300">
                <div>Dual approval: <span class="font-medium {{ $execution->requires_dual_approval ? 'text-amber-400' : 'text-gray-400' }}">{{ $execution->requires_dual_approval ? 'Required' : 'Not required' }}</span></div>
                <div>Approver 1: <span class="font-medium">{{ $execution->approver1?->name ?? '—' }}</span></div>
                @if($execution->requires_dual_approval)
                <div>Approver 2: <span class="font-medium">{{ $execution->approver2?->name ?? '—' }}</span></div>
                @endif
                <div>Expires: <span class="font-medium text-gray-500">{{ $execution->approval_expires_at?->diffForHumans() }}</span></div>
            </div>

            @if($execution->status === 'pending_approval' && auth()->id() !== $execution->created_by)
            <form method="POST" action="{{ route('active-response.approve', $execution->execution_id) }}" class="mt-3 flex gap-3 items-end">
                @csrf
                <div class="flex-1"><input type="text" name="rationale" placeholder="Approval rationale (required)"
                    class="w-full bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5" /></div>
                <button type="submit" class="px-4 py-1.5 rounded bg-green-700/40 border border-green-400/30 text-green-200 text-sm">Approve</button>
            </form>
            <form method="POST" action="{{ route('active-response.reject', $execution->execution_id) }}" class="mt-2 flex gap-3">
                @csrf
                <input type="text" name="reason" placeholder="Rejection reason" class="flex-1 bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5" />
                <button type="submit" class="px-4 py-1.5 rounded bg-red-700/30 border border-red-400/30 text-red-300 text-sm">Reject</button>
            </form>
            @endif
        </div>

        {{-- Action buttons by state --}}
        <div class="flex flex-wrap gap-2">
            @if($execution->status === 'draft')
            <form method="POST" action="{{ route('active-response.submit', $execution->execution_id) }}">@csrf
                <button class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm">Submit for Approval</button>
            </form>
            @endif

            @if($execution->status === 'approved')
            <form method="POST" action="{{ route('active-response.simulate', $execution->execution_id) }}">@csrf
                <button class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm">Run Simulation</button>
            </form>
            @endif

            @if($execution->status === 'simulated')
            <a href="{{ route('active-response.simulation-preview', $execution->execution_id) }}" class="px-4 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 text-sm">View Simulation</a>
            <form method="POST" action="{{ route('active-response.request-execution', $execution->execution_id) }}">@csrf
                <button class="px-4 py-1.5 rounded bg-amber-700/40 border border-amber-400/30 text-amber-200 text-sm">Request Execution</button>
            </form>
            @endif

            @if($execution->status === 'execution_ready')
            <form method="POST" action="{{ route('active-response.execute', $execution->execution_id) }}" class="flex gap-2 items-center">
                @csrf
                <input type="text" name="confirmation_note" placeholder="Execution confirmation note (required, min 10 chars)"
                    class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-72" />
                <button class="px-4 py-1.5 rounded bg-red-700/40 border border-red-400/30 text-red-300 text-sm">Confirm Execution</button>
            </form>
            @endif

            @if($execution->status === 'executed' && $execution->rollback_supported)
            <form method="POST" action="{{ route('active-response.initiate-rollback', $execution->execution_id) }}">@csrf
                <button class="px-4 py-1.5 rounded bg-purple-700/40 border border-purple-400/30 text-purple-200 text-sm">Initiate Rollback</button>
            </form>
            @endif

            @if($execution->status === 'rollback_ready')
            <form method="POST" action="{{ route('active-response.complete-rollback', $execution->execution_id) }}" class="flex gap-2 items-center">
                @csrf
                <input type="text" name="note" placeholder="Rollback completion note"
                    class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-56" />
                <button class="px-4 py-1.5 rounded bg-purple-700/50 border border-purple-400/30 text-purple-200 text-sm">Complete Rollback</button>
            </form>
            @endif

            @if(!in_array($execution->status, ['executed','rolled_back','failed','cancelled']))
            <form method="POST" action="{{ route('active-response.cancel', $execution->execution_id) }}" class="flex gap-2">
                @csrf
                <input type="text" name="reason" placeholder="Cancellation reason" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-48" />
                <button class="px-4 py-1.5 rounded border border-gray-600 text-gray-400 text-sm hover:text-gray-200">Cancel</button>
            </form>
            @endif

            <a href="{{ route('active-response.execution-timeline', $execution->execution_id) }}" class="px-3 py-1.5 rounded border border-cyan-200/20 text-cyan-400/70 text-sm hover:text-cyan-300">Timeline</a>
        </div>

        {{-- Events --}}
        @if($execution->events->isNotEmpty())
        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-2">Audit Trail ({{ $execution->events->count() }})</h3>
            <div class="space-y-1">
                @foreach($execution->events->sortByDesc('created_at') as $ev)
                <div class="text-xs flex gap-3 text-gray-300 p-1 rounded bg-gray-800/20">
                    <span class="text-gray-500 w-32 shrink-0">{{ $ev->created_at?->format('Y-m-d H:i:s') }}</span>
                    <span class="text-cyan-400">{{ $ev->event_type }}</span>
                    @if($ev->from_state || $ev->to_state)
                    <span class="text-gray-500">{{ $ev->from_state }} → {{ $ev->to_state }}</span>
                    @endif
                    <span class="text-gray-400">{{ $ev->actor_name }}</span>
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>
</x-app-layout>
