<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Execution Approval Queue</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Controlled manual response only. Creator cannot self-approve.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-5">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Approval Rules:</strong> Creator cannot self-approve. Dual approval required for: {{ implode(', ', array_map(fn($a) => str_replace('_', ' ', $a), \App\Models\ResponseExecution::DUAL_APPROVAL_REQUIRED)) }}.
        </div>

        @if($pending->isEmpty())
            <p class="text-sm text-gray-500">No pending approvals.</p>
        @else
        <div class="space-y-3">
            @foreach($pending as $exec)
            <div class="rounded-lg border border-amber-400/20 bg-gray-900/40 p-4">
                <div class="flex items-start justify-between">
                    <div class="space-y-1">
                        <div class="flex gap-3 text-sm">
                            <a href="{{ route('active-response.show', $exec->execution_id) }}" class="text-cyan-400 hover:underline font-mono">{{ $exec->execution_id }}</a>
                            <span class="text-amber-300 font-medium">{{ str_replace('_', ' ', $exec->action_type) }}</span>
                            <span class="text-gray-400">→</span>
                            <span class="text-gray-300 font-mono">{{ $exec->target_entity_key }}</span>
                        </div>
                        <div class="text-xs text-gray-500">
                            Requested by: <span class="text-gray-300">{{ $exec->creator?->name }}</span>
                            &middot; Expires: {{ $exec->approval_expires_at?->diffForHumans() }}
                            @if($exec->requires_dual_approval) &middot; <span class="text-amber-400">Dual approval required</span> @endif
                        </div>
                        <div class="text-xs text-gray-400">Rationale: {{ $exec->rationale }}</div>
                        <div class="text-xs">Blast radius: <span class="text-{{ $exec->blast_radius_score > 0.6 ? 'red' : 'gray' }}-400">{{ number_format($exec->blast_radius_score * 100) }}%</span></div>
                    </div>
                    @if(auth()->id() !== $exec->created_by)
                    <form method="POST" action="{{ route('active-response.approve', $exec->execution_id) }}" class="flex gap-2 items-center">
                        @csrf
                        <input type="text" name="rationale" placeholder="Approval rationale" class="bg-gray-800 border border-gray-600 text-gray-200 text-xs rounded px-2 py-1 w-48" />
                        <button class="px-3 py-1 rounded bg-green-700/40 border border-green-400/30 text-green-200 text-xs">Approve</button>
                    </form>
                    @else
                    <span class="text-xs text-gray-600 italic">You created this — cannot self-approve</span>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>
</x-app-layout>
