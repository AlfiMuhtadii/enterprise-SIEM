<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Endpoint Network Connections</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Endpoint telemetry is advisory-only. No autonomous containment or enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Network connection telemetry is advisory-only. No network blocking, firewall rule insertion, or IP isolation is performed.
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 mb-1">Total Connections (7d)</div>
                <div class="text-2xl font-bold text-blue-300">{{ $networkStats['total_connections'] }}</div>
            </div>
        </div>

        <div class="glass-card p-4 space-y-2">
            <h3 class="text-sm font-semibold text-cyan-200">Hunt for Network Connections</h3>
            <p class="text-xs text-gray-400">Use the Threat Hunt engine with domain <code class="text-cyan-400">endpoint_network_connections</code> to pivot on destination IP, port, process, and confidence score.</p>
            <a href="{{ route('threat-hunt.dashboard') }}" class="inline-block mt-2 px-3 py-1.5 text-xs rounded bg-cyan-900/30 text-cyan-300 border border-cyan-700/40 hover:bg-cyan-900/50">Open Threat Hunt →</a>
        </div>

    </div>
</x-app-layout>
