<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Endpoint Process Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Endpoint telemetry is advisory-only. No autonomous containment or enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Process telemetry is advisory-only. No autonomous containment or enforcement is executed.
        </div>

        <div class="grid grid-cols-4 gap-4">
            @foreach([
                ['Total Processes (7d)', $stats['total_processes'], 'text-gray-300'],
                ['Shell Processes', $stats['shell_processes'], 'text-yellow-300'],
                ['Suspicious', $stats['suspicious'], 'text-red-300'],
                ['Long-Lived', $stats['long_lived'], 'text-orange-300'],
            ] as [$label, $val, $color])
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 mb-1">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ $val }}</div>
            </div>
            @endforeach
        </div>

        <div class="glass-card p-4 space-y-3">
            <h3 class="text-sm font-semibold text-cyan-200">Top Script Interpreters (7d)</h3>
            @forelse($topInterpreters as $interp)
            <div class="flex justify-between items-center text-xs text-gray-300 border-b border-gray-700/40 pb-1">
                <span class="font-mono text-purple-300">{{ $interp->process_name }}</span>
                <span class="text-gray-400">{{ $interp->execution_count }} executions</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No script executions in the last 7 days.</p>
            @endforelse
        </div>

        <div class="text-xs text-gray-500 italic">
            Use the <a href="{{ route('threat-hunt.dashboard') }}" class="underline text-cyan-500">Threat Hunt</a> engine with domain <code>endpoint_process_executions</code> for pivot queries.
        </div>

    </div>
</x-app-layout>
