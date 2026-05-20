<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">SOAR Rollback Readiness Viewer</h2>
        <p class="text-xs text-amber-400/80 mt-1">SOAR workflows are simulation-first and approval-gated. No autonomous enforcement or destructive response is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Rollback Notice:</strong> Rollback execution requires approval. No destructive rollback without operator sign-off. All rollback plans are advisory-scope.
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Rollback Plans ({{ $rollbacks->count() }})</h3>
            <div class="space-y-2">
                @forelse($rollbacks as $rb)
                <div class="py-2 border-b border-gray-800 last:border-0 text-xs">
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-gray-400">{{ $rb->plan_id }}</span>
                        <span class="px-1.5 py-0.5 rounded
                            @if($rb->rollback_readiness_state === 'validated') bg-green-900/40 text-green-300
                            @elseif($rb->rollback_readiness_state === 'ready') bg-blue-900/40 text-blue-300
                            @elseif($rb->rollback_readiness_state === 'executed') bg-gray-700 text-gray-400
                            @else bg-red-900/40 text-red-300 @endif">{{ $rb->rollback_readiness_state }}</span>
                        @if($rb->simulation_validated)
                        <span class="text-green-400">SIM-VALIDATED</span>
                        @endif
                        @if($rb->approval_required)
                        <span class="text-yellow-400">APPROVAL-REQUIRED</span>
                        @endif
                    </div>
                    <div class="text-gray-500 mt-1">Scope: {{ $rb->rollback_scope }}</div>
                </div>
                @empty
                <p class="text-xs text-gray-500">No rollback plans defined.</p>
                @endforelse
            </div>
        </div>

    </div>
</x-app-layout>
