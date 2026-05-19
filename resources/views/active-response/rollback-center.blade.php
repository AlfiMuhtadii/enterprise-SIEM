<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Rollback Center</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Manual rollback only. No autonomous reversal.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-5">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Rollback is operator-initiated only. Supported for: {{ implode(', ', array_map(fn($a) => str_replace('_', ' ', $a), \App\Models\ResponseExecution::ROLLBACK_SUPPORTED_ACTIONS)) }}.
            Rollback window: {{ \App\Models\ResponseExecution::ROLLBACK_DEADLINE_HOURS }} hours from execution.
        </div>

        @if($candidates->isEmpty())
            <p class="text-sm text-gray-500">No executed actions eligible for rollback.</p>
        @else
        <div class="space-y-3">
            @foreach($candidates as $exec)
            <div class="rounded-lg border border-{{ $exec->status === 'rollback_ready' ? 'purple' : 'cyan' }}-400/20 bg-gray-900/40 p-4">
                <div class="flex items-center justify-between">
                    <div class="space-y-1">
                        <div class="flex gap-3 text-sm">
                            <a href="{{ route('active-response.show', $exec->execution_id) }}" class="text-cyan-400 hover:underline font-mono">{{ $exec->execution_id }}</a>
                            <span class="text-gray-200">{{ str_replace('_', ' ', $exec->action_type) }}</span>
                            <span class="font-mono text-gray-400">{{ $exec->target_entity_key }}</span>
                        </div>
                        <div class="text-xs text-gray-500">
                            Status: <span class="text-{{ $exec->status === 'rollback_ready' ? 'purple' : 'green' }}-400">{{ str_replace('_', ' ', $exec->status) }}</span>
                            @if($exec->rollback_deadline_at)
                            &middot; Deadline: <span class="{{ $exec->rollback_deadline_at->isPast() ? 'text-red-400' : 'text-gray-400' }}">{{ $exec->rollback_deadline_at->diffForHumans() }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="flex gap-2">
                        @if($exec->status === 'executed' && $exec->rollback_supported && !$exec->rollback_deadline_at?->isPast())
                        <form method="POST" action="{{ route('active-response.initiate-rollback', $exec->execution_id) }}">@csrf
                            <button class="px-3 py-1.5 rounded bg-purple-700/40 border border-purple-400/30 text-purple-200 text-sm">Initiate Rollback</button>
                        </form>
                        @endif
                        @if($exec->status === 'rollback_ready')
                        <form method="POST" action="{{ route('active-response.complete-rollback', $exec->execution_id) }}" class="flex gap-2">
                            @csrf
                            <input type="text" name="note" placeholder="Completion note" class="bg-gray-800 border border-gray-600 text-gray-200 text-xs rounded px-2 py-1 w-40" />
                            <button class="px-3 py-1.5 rounded bg-purple-700/50 border border-purple-400/30 text-purple-200 text-sm">Complete</button>
                        </form>
                        @endif
                    </div>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>
</x-app-layout>
