<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Endpoint Telemetry — Low Level Overview</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Endpoint telemetry is advisory-only. No autonomous containment or enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Endpoint telemetry is advisory-only. No autonomous containment or enforcement is executed.
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('endpoint-telemetry.process-explorer') }}" class="px-3 py-1.5 text-xs rounded bg-cyan-900/30 text-cyan-300 border border-cyan-700/40 hover:bg-cyan-900/50">Process Explorer</a>
            <a href="{{ route('endpoint-telemetry.network-connections') }}" class="px-3 py-1.5 text-xs rounded bg-blue-900/30 text-blue-300 border border-blue-700/40 hover:bg-blue-900/50">Network Connections</a>
            <a href="{{ route('endpoint-telemetry.script-execution') }}" class="px-3 py-1.5 text-xs rounded bg-purple-900/30 text-purple-300 border border-purple-700/40 hover:bg-purple-900/50">Script Execution</a>
            <a href="{{ route('endpoint-telemetry.privilege-escalation') }}" class="px-3 py-1.5 text-xs rounded bg-red-900/30 text-red-300 border border-red-700/40 hover:bg-red-900/50">Privilege Escalation</a>
            <a href="{{ route('endpoint-telemetry.persistence') }}" class="px-3 py-1.5 text-xs rounded bg-orange-900/30 text-orange-300 border border-orange-700/40 hover:bg-orange-900/50">Persistence Indicators</a>
            <a href="{{ route('endpoint-telemetry.container-activity') }}" class="px-3 py-1.5 text-xs rounded bg-green-900/30 text-green-300 border border-green-700/40 hover:bg-green-900/50">Container Activity</a>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach([
                ['Script Executions (7d)', $stats['script_executions'], 'text-purple-300'],
                ['Encoded Scripts', $stats['encoded_scripts'], 'text-red-400'],
                ['Privilege Escalations', $stats['privilege_escalations'], 'text-orange-400'],
                ['High-Conf Escalations', $stats['high_confidence_escalations'], 'text-red-300'],
                ['Container Activities', $stats['container_activities'], 'text-green-300'],
                ['Container Breakouts', $stats['container_breakouts'], 'text-red-500'],
            ] as [$label, $val, $color])
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 mb-1">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ $val }}</div>
            </div>
            @endforeach
        </div>

        <div class="grid grid-cols-2 gap-6">
            <div class="glass-card p-4 space-y-2">
                <h3 class="text-sm font-semibold text-cyan-200">Process Execution (7d)</h3>
                <div class="space-y-1 text-xs text-gray-300">
                    <div class="flex justify-between"><span>Total Processes</span><span class="text-white">{{ $processStats['total_processes'] }}</span></div>
                    <div class="flex justify-between"><span>Shell Processes</span><span class="text-yellow-300">{{ $processStats['shell_processes'] }}</span></div>
                    <div class="flex justify-between"><span>Suspicious</span><span class="text-red-300">{{ $processStats['suspicious'] }}</span></div>
                    <div class="flex justify-between"><span>Long-Lived</span><span class="text-orange-300">{{ $processStats['long_lived'] }}</span></div>
                </div>
            </div>
            <div class="glass-card p-4 space-y-2">
                <h3 class="text-sm font-semibold text-cyan-200">Persistence Indicators (7d)</h3>
                <div class="space-y-1 text-xs text-gray-300">
                    <div class="flex justify-between"><span>Total Items</span><span class="text-white">{{ $persistenceStats['total_items'] }}</span></div>
                    <div class="flex justify-between"><span>New Items</span><span class="text-orange-300">{{ $persistenceStats['new_items'] }}</span></div>
                    <div class="flex justify-between"><span>Network Connections</span><span class="text-blue-300">{{ $networkStats['total_connections'] }}</span></div>
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
