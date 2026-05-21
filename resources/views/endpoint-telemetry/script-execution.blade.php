<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Script Execution Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Endpoint telemetry is advisory-only. No autonomous containment or enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Script execution telemetry is advisory-only. No script blocking or process termination is performed.
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 mb-1">Total Executions (7d)</div>
                <div class="text-2xl font-bold text-purple-300">{{ $summary['total_executions'] }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 mb-1">Encoded Scripts</div>
                <div class="text-2xl font-bold text-red-400">{{ $summary['encoded_count'] }}</div>
            </div>
        </div>

        <div class="glass-card p-4 space-y-2">
            <h3 class="text-sm font-semibold text-cyan-200">Top Interpreters</h3>
            @forelse($summary['top_interpreters'] as $interp)
            <div class="flex justify-between items-center text-xs text-gray-300 border-b border-gray-700/40 pb-1">
                <span class="font-mono text-purple-300">{{ $interp->process_name }}</span>
                <span class="text-gray-400">{{ $interp->execution_count }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No script executions recorded.</p>
            @endforelse
        </div>

        <div class="glass-card p-4 space-y-2">
            <h3 class="text-sm font-semibold text-red-300">Encoded Script Executions</h3>
            @forelse($encodedScripts as $exec)
            <div class="text-xs text-gray-300 border-b border-gray-700/40 pb-2 space-y-0.5">
                <div class="flex justify-between">
                    <span class="font-mono text-purple-300">{{ $exec->process_name }}</span>
                    <span class="text-gray-500">{{ $exec->telemetry_source }}</span>
                </div>
                @if($exec->decoded_preview)
                <div class="text-gray-400 truncate font-mono text-xs">{{ $exec->decoded_preview }}</div>
                @endif
                <div class="text-gray-500">{{ $exec->occurred_at?->diffForHumans() }}</div>
            </div>
            @empty
            <p class="text-xs text-gray-500">No encoded script executions in the last 7 days.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
