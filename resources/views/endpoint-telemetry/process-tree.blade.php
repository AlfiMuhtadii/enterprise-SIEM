<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Process Tree Viewer</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Endpoint telemetry is advisory-only. No autonomous containment or enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Process tree telemetry is advisory-only. No process termination or host isolation is performed.
        </div>

        <div class="glass-card p-4 space-y-3">
            <h3 class="text-sm font-semibold text-cyan-200">Process Execution Overview (7d)</h3>
            <div class="grid grid-cols-2 gap-4 text-xs text-gray-300">
                <div class="flex justify-between"><span>Total Processes</span><span class="text-white">{{ $processStats['total_processes'] }}</span></div>
                <div class="flex justify-between"><span>Shell Processes</span><span class="text-yellow-300">{{ $processStats['shell_processes'] }}</span></div>
                <div class="flex justify-between"><span>Suspicious Chains</span><span class="text-red-300">{{ $processStats['suspicious'] }}</span></div>
                <div class="flex justify-between"><span>Long-Lived</span><span class="text-orange-300">{{ $processStats['long_lived'] }}</span></div>
            </div>
        </div>

        <div class="glass-card p-4 space-y-2">
            <h3 class="text-sm font-semibold text-cyan-200">Hunt for Process Chains</h3>
            <p class="text-xs text-gray-400">Use the Threat Hunt engine with domain <code class="text-cyan-400">endpoint_process_executions</code> to pivot on parent/child process relationships, command lines, and user context.</p>
            <a href="{{ route('threat-hunt.dashboard') }}" class="inline-block mt-2 px-3 py-1.5 text-xs rounded bg-cyan-900/30 text-cyan-300 border border-cyan-700/40 hover:bg-cyan-900/50">Open Threat Hunt →</a>
        </div>

    </div>
</x-app-layout>
